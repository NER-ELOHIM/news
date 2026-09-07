<?php

declare(strict_types=1);

/**
 * Servidor de mentira que imita a Messages API, para os testes exercitarem o
 * cliente HTTP de verdade sem chave e sem internet.
 *
 * O comportamento é escolhido pelo caminho da URL, e o corpo da resposta sai do
 * arquivo apontado por IODE_FAKE_PAYLOAD quando existe. Roda sob o servidor
 * embutido do PHP: `php -S 127.0.0.1:0 tests/fake-api.php`.
 */

$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json');

$responder = static function (array $corpo, int $status = 200): void {
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
};

switch ($caminho) {
    case '/recusa':
        $responder([
            'stop_reason' => 'refusal',
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber'],
            'content' => [],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 0],
        ]);
        break;

    case '/truncado':
        $responder([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'text', 'text' => '{"abertura":"']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 4],
        ]);
        break;

    case '/nao-json':
        $responder([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'desculpe, não consegui']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);
        break;

    case '/erro-cliente':
        $responder(['error' => ['type' => 'invalid_request_error', 'message' => 'credit balance is too low']], 400);
        break;

    default:
        $texto = getenv('IODE_FAKE_PAYLOAD') !== false && is_file((string) getenv('IODE_FAKE_PAYLOAD'))
            ? (string) file_get_contents((string) getenv('IODE_FAKE_PAYLOAD'))
            : '{"abertura":"","selecionados":[]}';

        $responder([
            'id' => 'msg_fake',
            'model' => 'claude-opus-5',
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage' => ['input_tokens' => 1234, 'output_tokens' => 567],
        ]);
}
