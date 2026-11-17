<?php

// The built-in server's router for CurlTransportTest.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
switch ($path) {
    case '/echo':
        header('Content-Type: application/json');
        header('X-Request-Id: req_echo');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'query' => $_SERVER['QUERY_STRING'] ?? '',
            'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
            'body' => $body,
        ]);
        break;
    case '/slow':
        usleep(1_500_000);
        echo '{}';
        break;
    case '/missing':
        http_response_code(404);
        header('Content-Type: application/json');
        header('X-Request-Id: req_404');
        echo '{"statusCode":404,"name":"not_found","message":"No such email"}';
        break;
    case '/events':
        header('Content-Type: text/event-stream');
        echo "id: 1\nevent: inbox.updated\ndata: {\"type\":\"inbox.updated\",\"inbox_id\":\"i1\",\"data\":{}}\n\n";
        echo "id: " . ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 'none') . "\nevent: inbox.deleted\ndata: {\"type\":\"inbox.deleted\",\"inbox_id\":\"i1\",\"data\":{}}\n\n";
        break;
    default:
        http_response_code(500);
}
