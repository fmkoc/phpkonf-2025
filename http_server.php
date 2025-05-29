<?php

/*
 * Simple HTTP Server using OpenSwoole
 * 
 * PHP Version: 8.4.X
 * OpenSwoole Version: 25.X
 * Author: FMK
 */

$server = new OpenSwoole\HTTP\Server("127.0.0.1", 9501);

$server->set([
    'worker_num' => 4,
    'task_worker_num' => 8,
    'backlog' => 128,
    //'task_enable_coroutine' => true, //need different onTask
]);

$server->on("WorkerStart", function($server, $workerId) {
    echo "Worker #$workerId started.\n";
});

$server->on("Start", function($server) {
    echo "OpenSwoole HTTP Server started at http://127.0.0.1:9501\n";
});

$server->on('Request', function(OpenSwoole\HTTP\Request $request, OpenSwoole\HTTP\Response $response) use ($server) {
    $start_time = microtime(true);
    echo "Request received: " . $request->server['request_uri'] . "\n";
    $server->task("Example task data");
    $response->end('
        <h1>Hello World!</h1>
        <p>Worker ID: ' . $server->worker_id . '</p>
        <p>Request Method: ' . $request->server['request_method'] . '</p>
        <p>Request URI: ' . $request->server['request_uri'] . '</p>
    ');
});

$server->on('Task', function(OpenSwoole\Server $server, int $task_id, int $reactor_id, string $data) {
    echo "Received task #{$task_id}: {$data}\n";
    sleep(5);
    return "Task completed: {$data}";
});

$server->on('Finish', function(OpenSwoole\Server $server, int $task_id, string $data) {
    echo "Task #{$task_id} finished. Result: {$data}\n";
});

$server->on("Shutdown", function($server) {
    echo "Server is shutting down...\n";
});

$server->on("WorkerStop", function($server, $workerId) {
    echo "Worker #{$workerId} stopped.\n";
});

$server->start();
