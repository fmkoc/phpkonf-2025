<?php

/*
 * XOX (Tic-Tac-Toe) WebSocket Game Server
 * Supports multiple worker processes with shared memory
 * 
 * PHP Version: 8.4.X
 * OpenSwoole Version: 25.X
 * Author: FMK
 */

use OpenSwoole\WebSocket\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use OpenSwoole\Table;

/**
 * XOX Game Server with shared memory tables for multi-worker support
 */
class XOXGameServer
{
    private Server $server;
    private Table $roomsTable;          // Shared table for game rooms
    private Table $connectionsTable;    // Shared table for client connections  
    private Table $playerRoomsTable;    // Shared table for player-room mapping

    /**
     * Initialize WebSocket server with given host and port
     */
    public function __construct(string $host = '0.0.0.0', int $port = 9501)
    {
        $this->server = new Server($host, $port);
        $this->initializeTables();
        $this->setupServer();
    }

    /**
     * Initialize shared memory tables for multi-worker communication
     */
    private function initializeTables(): void
    {
        // Rooms table - stores room information
        $this->roomsTable = new Table(1024);
        $this->roomsTable->column('id', Table::TYPE_STRING, 64);
        $this->roomsTable->column('name', Table::TYPE_STRING, 128);
        $this->roomsTable->column('players', Table::TYPE_STRING, 512); // JSON array of player IDs
        $this->roomsTable->column('game_data', Table::TYPE_STRING, 1024); // JSON game state
        $this->roomsTable->column('scores', Table::TYPE_STRING, 512); // JSON player scores
        $this->roomsTable->column('created_at', Table::TYPE_INT, 8);
        $this->roomsTable->create();

        // Connections table - stores connection information
        $this->connectionsTable = new Table(10000);
        $this->connectionsTable->column('nickname', Table::TYPE_STRING, 64);
        $this->connectionsTable->column('room_id', Table::TYPE_STRING, 64);
        $this->connectionsTable->column('player_number', Table::TYPE_INT, 4);
        $this->connectionsTable->column('connected_at', Table::TYPE_INT, 8);
        $this->connectionsTable->column('worker_id', Table::TYPE_INT, 4);
        $this->connectionsTable->create();

        // Player rooms mapping table - fast lookup for player's current room
        $this->playerRoomsTable = new Table(10000);
        $this->playerRoomsTable->column('room_id', Table::TYPE_STRING, 64);
        $this->playerRoomsTable->create();
        echo "OpenSwoole shared memory tables initialized successfully\n";
    }

    /**
     * Configure server settings and event handlers
     */
    private function setupServer(): void
    {
        // Server configuration - multiple workers with shared memory support
        $this->server->set([
            'worker_num' => 4,              // 4 worker processes for better performance
            'enable_coroutine' => true,     // Enable coroutine support
            'max_coroutine' => 100000,      // Maximum concurrent coroutines
        ]);

        // Register event handlers
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);

        // Periodic cleanup timer - runs every 30 seconds
        Timer::tick(30000, function () {
            $this->cleanupEmptyRooms();
        });
    }

    /**
     * Server start event handler
     */
    public function onStart(Server $server): void
    {
        echo "XOX Game Server started at ws://0.0.0.0:9501\n";
        echo "OpenSwoole version: " . OPENSWOOLE_VERSION . "\n";
        echo "PHP version: " . PHP_VERSION . "\n";
    }

    /**
     * Handle new client connection
     */
    public function onOpen(Server $server, Request $request): void
    {
        $fd = $request->fd;
        $worker_id = $server->worker_id;

        // Store connection in shared table for cross-worker access
        $this->connectionsTable->set($fd, [
            'nickname' => '',
            'room_id' => '',
            'player_number' => 0,
            'connected_at' => time(),
            'worker_id' => $worker_id
        ]);

        echo "New client connected: #{$fd} on worker #{$worker_id}\n";

        // Send welcome message to client
        $this->sendToClient($fd, 'connected', [
            'message' => "WebSocket connection established successfully",
            'fd' => $fd,
            'worker_id' => $worker_id
        ]);
    }

    /**
     * Handle incoming messages from clients
     */
    public function onMessage(Server $server, Frame $frame): void
    {
        // Process messages asynchronously using coroutines
        Coroutine::create(function () use ($frame) {
            $this->handleMessage($frame);
        });
    }

    /**
     * Process incoming message from client
     */
    private function handleMessage(Frame $frame): void
    {
        $data = json_decode($frame->data, true);

        // Validate message format
        if (!$data || !isset($data['action'])) {
            $this->sendError($frame->fd, 'Invalid message format');
            return;
        }

        // Route message to appropriate handler based on action
        switch ($data['action']) {
            case 'set_nickname':
                $this->setNickname($frame->fd, $data['nickname'] ?? '');
                break;

            case 'create_room':
                $this->createRoom($frame->fd, $data['room_name'] ?? '');
                break;

            case 'join_room':
                $this->joinRoom($frame->fd, $data['room_id'] ?? '');
                break;

            case 'leave_room':
                $this->leaveRoom($frame->fd);
                break;

            case 'make_move':
                $this->makeMove($frame->fd, $data['position'] ?? -1);
                break;

            case 'restart_game':
                $this->restartGame($frame->fd);
                break;

            case 'get_rooms':
                $this->getRooms($frame->fd);
                break;
            default:
                $this->sendError($frame->fd, 'Unknown action: ' . $data['action']);
        }
    }

    /**
     * Set nickname for a client connection
     */
    private function setNickname(int $fd, string $nickname): void
    {
        // Validate nickname length
        if (empty($nickname) || strlen($nickname) > 20) {
            $this->sendError($fd, 'Nickname must be between 1-20 characters');
            return;
        }

        // Get current connection data from shared table
        $connectionData = $this->connectionsTable->get($fd);
        if (!$connectionData) {
            $this->sendError($fd, 'Connection not found');
            return;
        }

        // Update nickname in shared table
        $connectionData['nickname'] = htmlspecialchars($nickname);
        $this->connectionsTable->set($fd, $connectionData);

        echo "Nickname set for connection #{$fd}: '{$connectionData['nickname']}'\n";

        $this->sendToClient($fd, 'nickname_set', [
            'nickname' => $connectionData['nickname']
        ]);
    }

    /**
     * Create a new game room
     */
    private function createRoom(int $fd, string $roomName): void
    {
        $connectionData = $this->connectionsTable->get($fd);
        if (!$connectionData) {
            $this->sendError($fd, 'Connection not found');
            return;
        }

        // Validate user has set a nickname
        if (empty($connectionData['nickname'])) {
            $this->sendError($fd, 'Please set a nickname first');
            return;
        }

        // Validate room name length
        if (empty($roomName) || strlen($roomName) > 30) {
            $this->sendError($fd, 'Room name must be between 1-30 characters');
            return;
        }

        $roomId = uniqid('room_');

        // Create room in shared table
        $this->roomsTable->set($roomId, [
            'id' => $roomId,
            'name' => htmlspecialchars($roomName),
            'players' => json_encode([$fd]),          // Creator is player 1
            'game_data' => json_encode($this->createNewGame()),
            'scores' => json_encode([$fd => 0]),      // Initialize scores
            'created_at' => time()
        ]);

        // Update connection data
        $connectionData['room_id'] = $roomId;
        $connectionData['player_number'] = 1;
        $this->connectionsTable->set($fd, $connectionData);

        // Set player room mapping for quick lookup
        $this->playerRoomsTable->set($fd, ['room_id' => $roomId]);

        echo "Room created: {$roomId} by {$connectionData['nickname']}\n";

        $this->sendToClient($fd, 'room_created', [
            'room_id' => $roomId,
            'room_name' => $roomName,
            'player_number' => 1
        ]);

        // Broadcast updated room list to all clients
        $this->broadcastRoomsListToAll();
        $this->broadcastRoomUpdate($roomId);
    }

    /**
     * Join an existing game room
     */
    private function joinRoom(int $fd, string $roomId): void
    {
        $connectionData = $this->connectionsTable->get($fd);
        if (!$connectionData) {
            $this->sendError($fd, 'Connection not found');
            return;
        }

        // Validate user has set a nickname
        if (empty($connectionData['nickname'])) {
            $this->sendError($fd, 'Please set a nickname first');
            return;
        }

        // Get room data from shared table
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) {
            $this->sendError($fd, 'Room not found');
            return;
        }

        // Check if room is not full
        $players = json_decode($roomData['players'], true);
        if (count($players) >= 2) {
            $this->sendError($fd, 'Room is full');
            return;
        }        // Add player to room as player 2
        $players[] = $fd;
        $scores = json_decode($roomData['scores'], true);
        $scores[$fd] = 0;

        // Reset game state when second player joins
        $newGameData = $this->createNewGame();

        // Update room data in shared table
        $roomData['players'] = json_encode($players);
        $roomData['scores'] = json_encode($scores);
        $roomData['game_data'] = json_encode($newGameData);
        $this->roomsTable->set($roomId, $roomData);

        // Update connection data
        $connectionData['room_id'] = $roomId;
        $connectionData['player_number'] = 2;
        $this->connectionsTable->set($fd, $connectionData);

        // Set player room mapping for quick lookup
        $this->playerRoomsTable->set($fd, ['room_id' => $roomId]);

        echo "Player {$connectionData['nickname']} joined room {$roomId} as player 2\n";

        $this->sendToClient($fd, 'room_joined', [
            'room_id' => $roomId,
            'room_name' => $roomData['name'],
            'player_number' => 2
        ]);

        // Broadcast updated room list to all clients
        $this->broadcastRoomsListToAll();
        $this->broadcastRoomUpdate($roomId);
        $this->startGame($roomId);
    }

    /**
     * Leave the current game room
     */
    private function leaveRoom(int $fd): void
    {
        $playerRoomData = $this->playerRoomsTable->get($fd);
        if (!$playerRoomData || empty($playerRoomData['room_id'])) {
            return;
        }

        $roomId = $playerRoomData['room_id'];
        $roomData = $this->roomsTable->get($roomId);
        if ($roomData) {
            $players = json_decode($roomData['players'], true);
            $players = array_filter($players, fn($playerId) => $playerId !== $fd);
            // Reindex array to ensure sequential keys
            $players = array_values($players);

            // Delete room if empty
            if (empty($players)) {
                $this->roomsTable->del($roomId);
                echo "Room {$roomId} deleted (empty)\n";
            } else {
                // Notify other player
                $connectionData = $this->connectionsTable->get($fd);
                $nickname = $connectionData ? $connectionData['nickname'] : 'Anonymous';

                // Reset game state when only one player remains
                $roomData['game_data'] = json_encode($this->createNewGame());

                // Update room with remaining players and reset player numbers
                $remainingPlayer = $players[0];
                $roomData['players'] = json_encode([$remainingPlayer]);

                // Update remaining player to be player 1
                $remainingPlayerData = $this->connectionsTable->get($remainingPlayer);
                if ($remainingPlayerData) {
                    $remainingPlayerData['player_number'] = 1;
                    $this->connectionsTable->set($remainingPlayer, $remainingPlayerData);
                }

                $this->roomsTable->set($roomId, $roomData);

                $this->broadcastToRoom($roomId, 'player_left', [
                    'message' => $nickname . ' left the room',
                    'game_reset' => true
                ]);
                $this->broadcastRoomUpdate($roomId);
            }

            // Broadcast updated room list to all connected clients
            $this->broadcastRoomsListToAll();
        }

        // Update connection data
        $connectionData = $this->connectionsTable->get($fd);
        if ($connectionData) {
            $connectionData['room_id'] = '';
            $connectionData['player_number'] = 0;
            $this->connectionsTable->set($fd, $connectionData);
        }

        $this->playerRoomsTable->del($fd);

        $this->sendToClient($fd, 'room_left', []);
    }

    /**
     * Handle player move in the game
     */
    private function makeMove(int $fd, int $position): void
    {
        $playerRoomData = $this->playerRoomsTable->get($fd);
        if (!$playerRoomData || empty($playerRoomData['room_id'])) {
            $this->sendError($fd, 'You are not in a room');
            return;
        }

        $roomId = $playerRoomData['room_id'];
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) {
            $this->sendError($fd, 'Room not found');
            return;
        }

        $connectionData = $this->connectionsTable->get($fd);
        if (!$connectionData) {
            $this->sendError($fd, 'Connection not found');
            return;
        }

        $players = json_decode($roomData['players'], true);
        $game = json_decode($roomData['game_data'], true);

        // Validate game conditions
        if (count($players) < 2) {
            $this->sendError($fd, 'Game requires 2 players');
            return;
        }

        if ($game['current_player'] !== $connectionData['player_number']) {
            $this->sendError($fd, 'Not your turn');
            return;
        }

        if ($position < 0 || $position > 8) {
            $this->sendError($fd, 'Invalid position');
            return;
        }

        if ($game['board'][$position] !== '') {
            $this->sendError($fd, 'Position already taken');
            return;
        }

        // Make the move
        $symbol = $game['current_player'] === 1 ? 'X' : 'O';
        $game['board'][$position] = $symbol;
        $game['moves']++;

        // Check for winner
        $winner = $this->checkWinner($game['board']);

        if ($winner) {
            // Game won - update scores and finish game
            $winnerId = $winner === 'X' ? $players[0] : $players[1];
            $scores = json_decode($roomData['scores'], true);
            $scores[$winnerId]++;
            $game['winner'] = $winner;
            $game['finished'] = true;

            // Update room data with new game state and scores
            $roomData['game_data'] = json_encode($game);
            $roomData['scores'] = json_encode($scores);
            $this->roomsTable->set($roomId, $roomData);

            $winnerConnectionData = $this->connectionsTable->get($winnerId);
            $this->broadcastToRoom($roomId, 'game_finished', [
                'winner' => $winner,
                'winner_nickname' => $winnerConnectionData ? $winnerConnectionData['nickname'] : 'Anonymous',
                'board' => $game['board'],
                'scores' => $this->getRoomScores($roomId)
            ]);

            // Start new game after 3 seconds
            Timer::after(3000, function () use ($roomId) {
                $roomData = $this->roomsTable->get($roomId);
                if ($roomData) {
                    $players = json_decode($roomData['players'], true);
                    if (count($players) === 2) {
                        $roomData['game_data'] = json_encode($this->createNewGame());
                        $this->roomsTable->set($roomId, $roomData);

                        $newGame = json_decode($roomData['game_data'], true);
                        $this->broadcastToRoom($roomId, 'new_game_started', [
                            'board' => $newGame['board'],
                            'current_player' => 1
                        ]);
                    }
                }
            });
        } elseif ($game['moves'] === 9) {
            // Draw game
            $game['finished'] = true;

            // Update room data
            $roomData['game_data'] = json_encode($game);
            $this->roomsTable->set($roomId, $roomData);

            $this->broadcastToRoom($roomId, 'game_finished', [
                'winner' => 'draw',
                'board' => $game['board'],
                'scores' => $this->getRoomScores($roomId)
            ]);

            // Start new game after 3 seconds
            Timer::after(3000, function () use ($roomId) {
                $roomData = $this->roomsTable->get($roomId);
                if ($roomData) {
                    $players = json_decode($roomData['players'], true);
                    if (count($players) === 2) {
                        $roomData['game_data'] = json_encode($this->createNewGame());
                        $this->roomsTable->set($roomId, $roomData);

                        $newGame = json_decode($roomData['game_data'], true);
                        $this->broadcastToRoom($roomId, 'new_game_started', [
                            'board' => $newGame['board'],
                            'current_player' => 1
                        ]);
                    }
                }
            });
        } else {
            // Continue game - switch turns
            $game['current_player'] = $game['current_player'] === 1 ? 2 : 1;

            // Update room data
            $roomData['game_data'] = json_encode($game);
            $this->roomsTable->set($roomId, $roomData);

            $this->broadcastToRoom($roomId, 'move_made', [
                'position' => $position,
                'symbol' => $symbol,
                'board' => $game['board'],
                'current_player' => $game['current_player'],
                'player_nickname' => $connectionData['nickname']
            ]);
        }
    }

    /**
     * Restart the current game in the room
     */
    private function restartGame(int $fd): void
    {
        $playerRoomData = $this->playerRoomsTable->get($fd);
        if (!$playerRoomData || empty($playerRoomData['room_id'])) {
            $this->sendError($fd, 'You are not in a room');
            return;
        }

        $roomId = $playerRoomData['room_id'];
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) {
            $this->sendError($fd, 'Room not found');
            return;
        }

        // Reset game state
        $roomData['game_data'] = json_encode($this->createNewGame());
        $this->roomsTable->set($roomId, $roomData);

        $newGame = json_decode($roomData['game_data'], true);
        $this->broadcastToRoom($roomId, 'game_restarted', [
            'board' => $newGame['board'],
            'current_player' => 1
        ]);
    }

    /**
     * Get list of available rooms for a client
     */
    private function getRooms(int $fd): void
    {
        $roomList = [];
        foreach ($this->roomsTable as $roomId => $room) {
            $players = json_decode($room['players'], true);
            $roomList[] = [
                'id' => $room['id'],
                'name' => $room['name'],
                'players' => count($players),
                'max_players' => 2,
                'can_join' => count($players) < 2
            ];
        }

        $this->sendToClient($fd, 'rooms_list', [
            'rooms' => $roomList
        ]);
    }

    /**
     * Create a new game state with empty board
     */
    private function createNewGame(): array
    {
        return [
            'board' => array_fill(0, 9, ''),
            'current_player' => 1,
            'moves' => 0,
            'winner' => null,
            'finished' => false,
            'started_at' => time()
        ];
    }

    /**
     * Check if there's a winner on the current board
     */
    private function checkWinner(array $board): ?string
    {
        $lines = [
            [0, 1, 2],
            [3, 4, 5],
            [6, 7, 8], // Horizontal
            [0, 3, 6],
            [1, 4, 7],
            [2, 5, 8], // Vertical
            [0, 4, 8],
            [2, 4, 6]             // Diagonal
        ];

        foreach ($lines as $line) {
            [$a, $b, $c] = $line;
            if ($board[$a] !== '' && $board[$a] === $board[$b] && $board[$a] === $board[$c]) {
                return $board[$a];
            }
        }

        return null;
    }

    /**
     * Start game when room has 2 players
     */
    private function startGame(string $roomId): void
    {
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) {
            return;
        }

        $players = json_decode($roomData['players'], true);
        if (count($players) < 2) {
            return;
        }

        $game = json_decode($roomData['game_data'], true);
        $this->broadcastToRoom($roomId, 'game_started', [
            'board' => $game['board'],
            'current_player' => 1,
            'players' => $this->getRoomPlayers($roomId)
        ]);
    }

    /**
     * Get formatted player list for a room
     */
    private function getRoomPlayers(string $roomId): array
    {
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) return [];

        $players = [];
        $playerIds = json_decode($roomData['players'], true);

        foreach ($playerIds as $index => $playerId) {
            $connectionData = $this->connectionsTable->get($playerId);
            $players[] = [
                'player_number' => $index + 1,
                'nickname' => $connectionData ? $connectionData['nickname'] : 'Anonymous',
                'symbol' => $index === 0 ? 'X' : 'O'
            ];
        }
        return $players;
    }

    /**
     * Get formatted score list for a room
     */
    private function getRoomScores(string $roomId): array
    {
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) return [];

        $scores = [];
        $playerIds = json_decode($roomData['players'], true);
        $roomScores = json_decode($roomData['scores'], true);

        foreach ($playerIds as $index => $playerId) {
            $connectionData = $this->connectionsTable->get($playerId);
            $scores[] = [
                'player_number' => $index + 1,
                'nickname' => $connectionData ? $connectionData['nickname'] : 'Anonymous',
                'score' => $roomScores[$playerId] ?? 0,
                'symbol' => $index === 0 ? 'X' : 'O'
            ];
        }
        return $scores;
    }

    /**
     * Broadcast room update to all players in the room
     */
    private function broadcastRoomUpdate(string $roomId): void
    {
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) {
            return;
        }

        $this->broadcastToRoom($roomId, 'room_updated', [
            'room' => [
                'id' => $roomId,
                'name' => $roomData['name'],
                'players' => $this->getRoomPlayers($roomId),
                'scores' => $this->getRoomScores($roomId)
            ]
        ]);
    }

    /**
     * Send message to all players in a specific room
     */

    private function broadcastToRoom(string $roomId, string $action, array $data): void
    {
        $roomData = $this->roomsTable->get($roomId);
        if (!$roomData) {
            return;
        }

        $playerIds = json_decode($roomData['players'], true);
        if (!is_array($playerIds)) {
            return;
        }

        foreach ($playerIds as $playerId) {
            // Ensure playerId is a valid integer before sending
            if (is_int($playerId) && $playerId > 0) {
                $this->sendToClient($playerId, $action, $data);
            }
        }
    }

    /**
     * Send message to a specific client with robust error handling
     */
    private function sendToClient(int $fd, string $action, array $data): void
    {
        // Validate fd parameter
        if (!is_int($fd) || $fd <= 0) {
            echo "Warning: Invalid file descriptor passed to sendToClient: " . var_export($fd, true) . "\n";
            return;
        }

        try {
            // Double check: connection should exist in our table and be established
            $connectionData = $this->connectionsTable->get($fd);
            if (!$connectionData) {
                return;
            }

            // Check if connection is still established
            if (!$this->server->isEstablished($fd)) {
                // Clean up the connection from our tables
                $this->cleanupConnection($fd);
                return;
            }

            $message = json_encode([
                'action' => $action,
                'data' => $data,
                'timestamp' => time()
            ]);

            // Attempt to send the message
            $result = $this->server->push($fd, $message);
            if (!$result) {
                // Connection might be closed, clean it up
                $this->cleanupConnection($fd);
            }
        } catch (Exception $e) {
            echo "[ERROR] Exception while sending to #{$fd}: " . $e->getMessage() . "\n";
            // Clean up potentially stale connection
            $this->cleanupConnection($fd);
        }
    }

    /**
     * Clean up stale connections from all shared tables
     */
    private function cleanupConnection(int $fd): void
    {
        // Remove from tables if exists
        if ($this->connectionsTable->exists($fd)) {
            $this->connectionsTable->del($fd);
        }

        if ($this->playerRoomsTable->exists($fd)) {
            $this->playerRoomsTable->del($fd);
        }

        // Also remove from any rooms
        foreach ($this->roomsTable as $roomId => $room) {
            $players = json_decode($room['players'], true);
            $originalCount = count($players);
            $players = array_filter($players, fn($playerId) => $playerId !== $fd);
            if (count($players) !== $originalCount) {
                if (empty($players)) {
                    $this->roomsTable->del($roomId);
                    echo "Room {$roomId} deleted (empty after cleanup)\n";
                } else {
                    // Reset game state when only one player remains after cleanup
                    $room['game_data'] = json_encode($this->createNewGame());

                    // Update remaining player to be player 1
                    $remainingPlayer = $players[0];
                    $remainingPlayerData = $this->connectionsTable->get($remainingPlayer);
                    if ($remainingPlayerData) {
                        $remainingPlayerData['player_number'] = 1;
                        $this->connectionsTable->set($remainingPlayer, $remainingPlayerData);
                    }

                    $room['players'] = json_encode(array_values($players));
                    $this->roomsTable->set($roomId, $room);

                    // Notify remaining player about game reset
                    $this->sendToClient($remainingPlayer, 'player_disconnected', [
                        'message' => 'Other player disconnected. Waiting for new player...',
                        'game_reset' => true
                    ]);
                }
            }
        }
    }

    /**
     * Send error message to client
     */
    private function sendError(int $fd, string $message): void
    {
        $this->sendToClient($fd, 'error', ['message' => $message]);
    }

    /**
     * Periodic cleanup of empty or old rooms
     */
    private function cleanupEmptyRooms(): void
    {
        foreach ($this->roomsTable as $roomId => $room) {
            $players = json_decode($room['players'], true);
            // Remove empty rooms or rooms older than 1 hour
            if (
                empty($players) ||
                (time() - $room['created_at']) > 3600
            ) {
                $this->roomsTable->del($roomId);
                echo "Empty room cleaned up: $roomId\n";
            }
        }
    }

    /**
     * Broadcast room list to all connected clients
     */
    private function broadcastRoomsListToAll(): void
    {
        $roomList = [];
        foreach ($this->roomsTable as $roomId => $room) {
            $players = json_decode($room['players'], true);
            $roomList[] = [
                'id' => $room['id'],
                'name' => $room['name'],
                'players' => count($players),
                'max_players' => 2,
                'can_join' => count($players) < 2
            ];
        }

        // Broadcast to all connections in shared table
        foreach ($this->connectionsTable as $fd => $connection) {
            $this->sendToClient($fd, 'rooms_list', [
                'rooms' => $roomList
            ]);
        }
    }

    /**
     * Handle client disconnection
     */
    public function onClose(Server $server, int $fd): void
    {
        $connectionData = $this->connectionsTable->get($fd);
        $nickname = $connectionData ? $connectionData['nickname'] : 'Anonymous';
        echo "Connection #{$fd} ({$nickname}) closed\n";

        // Remove user from room
        $this->leaveRoom($fd);

        // Clean up connection data from shared tables
        $this->connectionsTable->del($fd);
        $this->playerRoomsTable->del($fd);

        echo "Total active connections: " . $this->connectionsTable->count() . "\n";
        echo "Total active rooms: " . $this->roomsTable->count() . "\n";
    }

    /**
     * Start the WebSocket server
     */
    public function start(): void
    {
        $this->server->start();
    }
}

// Start the server
$server = new XOXGameServer();
$server->start();