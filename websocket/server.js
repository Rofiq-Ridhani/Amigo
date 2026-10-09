const http = require('http');
const socketIO = require('socket.io');
const redis = require('redis');
const jwt = require('jsonwebtoken');

// Config
const SOCKET_PORT = process.env.SOCKET_PORT || 3001;
const REVERB_APP_KEY = process.env.REVERB_APP_KEY || 'ctgffadrw9mpxmwpw7by';
const REVERB_APP_SECRET = process.env.REVERB_APP_SECRET || 'vfehdhqp0eodr4mzwne';
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = process.env.REDIS_PORT || 6379;
const REDIS_PASSWORD = process.env.REDIS_PASSWORD || null;
const REDIS_CHAT_CHANNEL = process.env.REDIS_CHAT_CHANNEL || 'chat.messages';
const REDIS_PRESENCE_CHANNEL = process.env.REDIS_PRESENCE_CHANNEL || 'chat.presence';
const REDIS_NOTIF_CHANNEL = process.env.REDIS_NOTIF_CHANNEL || 'chat.notifications';

// Express-less HTTP server for Socket.IO
const server = http.createServer((req, res) => {
    if (req.url === '/health') {
        res.writeHead(200);
        res.end('OK');
    } else {
        res.writeHead(404);
        res.end('Not Found');
    }
});

// Socket.IO init
const io = socketIO(server, {
    cors: {
        origin: process.env.FRONTEND_URL || 'http://Amigo.test',
        methods: ['GET', 'POST'],
        credentials: true,
    },
    transports: ['websocket', 'polling'],
});

// Redis clients
const redisPublisher = redis.createClient({
    host: REDIS_HOST,
    port: REDIS_PORT,
    password: REDIS_PASSWORD,
    retry_strategy: (opts) => Math.min(opts.attempt * 100, 3000),
});

const redisSubscriber = redis.createClient({
    host: REDIS_HOST,
    port: REDIS_PORT,
    password: REDIS_PASSWORD,
    retry_strategy: (opts) => Math.min(opts.attempt * 100, 3000),
});

// In-memory state (ponytail: upgrade to Redis for horizontal scaling)
const userSessions = new Map(); // { userId: { socketId, userName, status, handshake } }
const userRooms = new Map();    // { userId: Set of socketIds }
const handshakes = new Map();   // { "A:B": { status, initiator, acceptor, expiry } }

redisPublisher.on('error', (err) => console.error('Redis Publisher error:', err));
redisSubscriber.on('error', (err) => console.error('Redis Subscriber error:', err));

redisSubscriber.subscribe(
    REDIS_CHAT_CHANNEL,
    REDIS_PRESENCE_CHANNEL,
    REDIS_NOTIF_CHANNEL,
    (err) => {
        if (err) console.error('Subscribe error:', err);
        else console.log(`[Redis] Subscribed to: ${REDIS_CHAT_CHANNEL}, ${REDIS_PRESENCE_CHANNEL}, ${REDIS_NOTIF_CHANNEL}`);
    }
);

// Redis message listener
redisSubscriber.on('message', (channel, message) => {
    try {
        const data = JSON.parse(message);

        if (channel === REDIS_CHAT_CHANNEL) {
            // Broadcast message:new to recipient room
            if (data.event === 'message:new') {
                io.to(`chat.${data.payload.recipient_id}`).emit('message:new', data.payload);
            } else if (data.event === 'message:edited') {
                io.to(`chat.${data.payload.recipient_id}`).emit('message:edited', data.payload);
            } else if (data.event === 'message:deleted') {
                io.to(`chat.${data.payload.recipient_id}`).emit('message:deleted', data.payload);
            }
        } else if (channel === REDIS_PRESENCE_CHANNEL) {
            // Broadcast presence to all connected clients
            if (data.event === 'presence:update') {
                io.emit('presence:update', data.payload);
            }
        } else if (channel === REDIS_NOTIF_CHANNEL) {
            // Broadcast notification to user's room
            if (data.event === 'notification:new') {
                io.to(`notif.${data.payload.user_id}`).emit('notification:new', data.payload);
            }
        }
    } catch (err) {
        console.error(`[Redis] Parse error on ${channel}:`, err);
    }
});

// Socket.IO middleware — JWT auth
io.use((socket, next) => {
    const token = socket.handshake.auth.token || socket.handshake.headers.authorization?.replace('Bearer ', '');

    if (!token) {
        return next(new Error('Authentication error: no token'));
    }

    try {
        // Verify JWT (same as Laravel §4.5)
        const key = process.env.APP_KEY
            ? process.env.APP_KEY.replace(/^base64:/, '')
            : REVERB_APP_SECRET;
        
        const decoded = jwt.verify(token, Buffer.from(key, 'base64'), { algorithms: ['HS256'] });
        socket.userId = decoded.sub;
        socket.userEmail = decoded.email;
        next();
    } catch (err) {
        next(new Error(`Authentication error: ${err.message}`));
    }
});

// Connection handler
io.on('connection', (socket) => {
    const userId = socket.userId;
    const userName = socket.userEmail?.split('@')[0] || `User${userId}`;

    console.log(`[Connect] User ${userId} (${userName}) - socket ${socket.id}`);

    // Track session
    userSessions.set(userId, {
        socketId: socket.id,
        userName,
        status: 'online',
        connectedAt: new Date(),
    });

    // Auto-join personal rooms
    socket.join(`chat.${userId}`);        // Receive messages
    socket.join(`notif.${userId}`);       // Receive notifications
    socket.join('presence');              // Receive presence updates

    // Send presence snapshot on connect
    const onlineUsers = Array.from(userSessions.values()).map((s) => ({
        user_id: userId,
        status: 'online',
    }));
    socket.emit('presence:snapshot', { users: onlineUsers });

    // Broadcast user came online
    io.emit('presence:update', { user_id: userId, status: 'online' });

    // ponytail: publish to Redis for multi-server presence sync
    redisPublisher.publish(REDIS_PRESENCE_CHANNEL, JSON.stringify({
        event: 'presence:update',
        payload: { user_id: userId, status: 'online' },
    }));

    /**
     * Handshake: User A initiates conversation with B
     * 1. A emit handshake:start {target_user_id: B}
     * 2. Server check: B online?
     *    - if yes: emit handshake:request to B
     *    - if no: emit handshake:pending to A (retry)
     * 3. B emit handshake:accept {from_user_id: A}
     * 4. Server emit handshake:complete to both
     */
    socket.on('handshake:start', (data, ack) => {
        const targetUserId = data.target_user_id;
        const handshakeKey = [userId, targetUserId].sort().join(':');

        // Check if already handshaking or completed
        const existing = handshakes.get(handshakeKey);
        if (existing && existing.status === 'completed' && existing.expiry > Date.now()) {
            if (ack) ack({ ok: true, status: 'already_completed' });
            return;
        }

        // Check if target is online
        const targetSession = userSessions.get(targetUserId);
        if (!targetSession) {
            if (ack) ack({ ok: false, message: 'Target user offline' });
            return;
        }

        // Create handshake record
        handshakes.set(handshakeKey, {
            status: 'pending',
            initiator: userId,
            acceptor: targetUserId,
            expiry: Date.now() + 30000, // 30s TTL
        });

        // Send request to target
        io.to(`chat.${targetUserId}`).emit('handshake:request', {
            from_user_id: userId,
            from_user_name: userName,
        });

        if (ack) ack({ ok: true, status: 'request_sent' });
    });

    /**
     * Handshake: User B accepts conversation from A
     */
    socket.on('handshake:accept', (data, ack) => {
        const fromUserId = data.from_user_id;
        const handshakeKey = [fromUserId, userId].sort().join(':');
        const handshake = handshakes.get(handshakeKey);

        if (!handshake || handshake.status !== 'pending') {
            if (ack) ack({ ok: false, message: 'No pending handshake' });
            return;
        }

        // Mark as completed
        handshake.status = 'completed';
        handshake.expiry = Date.now() + 3600000; // 1 hour TTL

        // Emit completion to both
        io.to(`chat.${fromUserId}`).emit('handshake:complete', {
            with_user_id: userId,
            status: 'ready',
        });
        io.to(`chat.${userId}`).emit('handshake:complete', {
            with_user_id: fromUserId,
            status: 'ready',
        });

        if (ack) ack({ ok: true });
    });

    /**
     * Message: Receive ACK from client
     * Client emit this after receiving message:new
     */
    socket.on('acknowledge', (data) => {
        const messageId = data.message_id;
        console.log(`[ACK] Message ${messageId} from user ${userId}`);
        // ponytail: log ACK to DB for delivery tracking
    });

    /**
     * Typing: User starts typing
     */
    socket.on('typing:start', (data) => {
        const conversationUserId = data.conversation_user_id;
        io.to(`chat.${conversationUserId}`).emit('typing:indicator', {
            user_id: userId,
            user_name: userName,
            is_typing: true,
        });
    });

    /**
     * Typing: User stops typing
     */
    socket.on('typing:stop', (data) => {
        const conversationUserId = data.conversation_user_id;
        io.to(`chat.${conversationUserId}`).emit('typing:indicator', {
            user_id: userId,
            user_name: userName,
            is_typing: false,
        });
    });

    /**
     * Disconnect: User goes offline
     */
    socket.on('disconnect', (reason) => {
        console.log(`[Disconnect] User ${userId} - ${reason}`);

        userSessions.delete(userId);

        // Broadcast user went offline
        io.emit('presence:update', { user_id: userId, status: 'offline' });
        redisPublisher.publish(REDIS_PRESENCE_CHANNEL, JSON.stringify({
            event: 'presence:update',
            payload: { user_id: userId, status: 'offline' },
        }));

        // Auto-stop typing indicator
        io.emit('typing:indicator', {
            user_id: userId,
            user_name: userName,
            is_typing: false,
        });
    });

    socket.on('error', (err) => {
        console.error(`[Socket Error] User ${userId}:`, err);
    });
});

// Cleanup expired handshakes every minute
setInterval(() => {
    const now = Date.now();
    for (const [key, handshake] of handshakes.entries()) {
        if (handshake.expiry < now) {
            handshakes.delete(key);
        }
    }
}, 60000);

server.listen(SOCKET_PORT, () => {
    console.log(`[Socket.IO] Listening on port ${SOCKET_PORT}`);
    console.log(`[Redis] Connected to ${REDIS_HOST}:${REDIS_PORT}`);
    console.log(`[Channels] ${REDIS_CHAT_CHANNEL}, ${REDIS_PRESENCE_CHANNEL}, ${REDIS_NOTIF_CHANNEL}`);
});

process.on('SIGTERM', () => {
    console.log('[SIGTERM] Shutting down gracefully...');
    io.close();
    redisPublisher.quit();
    redisSubscriber.quit();
    server.close(() => process.exit(0));
});
