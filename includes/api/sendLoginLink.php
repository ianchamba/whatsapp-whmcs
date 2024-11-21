<?php
// Inclua o init.php do WHMCS para carregar o ambiente
require_once __DIR__ . '/../../init.php';

use WHMCS\Database\Capsule;

// Verifica se a requisição possui o parâmetro 'action=sendLoginLink' e se é uma requisição POST
if (isset($_GET['action']) && $_GET['action'] === 'sendLoginLink' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Captura o JSON da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    $email = isset($input['email']) ? $input['email'] : null;

    // Log para debugging
    error_log("Requisição recebida no sendLoginLink.php com o e-mail: " . print_r($email, true));

    if ($email) {
        // Realiza a busca do cliente no WHMCS
        $client = localAPI('GetClientsDetails', ['email' => $email]);

        if ($client['result'] === 'success') {
            // Cria o token de login único
            $createToken = localAPI('CreateSsoToken', [
                'client_id' => $client['id']
            ]);

            if ($createToken['result'] === 'success') {
                // Gera o link de login
                $link = $createToken['redirect_url'];

                // Envia o e-mail usando o template padrão do WHMCS
                $emailParams = [
                    'messagename' => 'Magic Login', // Nome do template de e-mail no WHMCS
                    'id' => $client['id'], // ID do cliente
                    'customvars' => base64_encode(serialize([
                        'login_link' => $link // Passa o link como uma variável personalizada
                    ]))
                ];

                $sendEmail = localAPI('SendEmail', $emailParams);

                if ($sendEmail['result'] === 'success') {
                    // Envio do WhatsApp
                    $phoneNumber = processarNumeroTelefone($client['phonenumberformatted']);
                    $whatsappUrl = "https://seudominio/message/sendText/Hostbraza";
                    $headers = [
                        'Content-Type: application/json',
                        'apikey: SUA_API_KEY'
                    ];
                    $text = "*Acesso Rápido* ️\n\n".
                            "Olá {$client['firstname']}, tudo bem? ☺️\n\n".
                            "Aqui está o link para acessar sua conta rapidamente:\n\n".
                            "$link\n\n".
                            "_Equipe Hostbraza_";

                    $body = [
                        "number" => $phoneNumber,
                        "text" => $text,
                        "linkPreview" => false
                    ];

                    // Inicializa o cURL para enviar a mensagem pelo WhatsApp
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $whatsappUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                    // Executa a solicitação e captura a resposta
                    $whatsappResponse = curl_exec($ch);
                    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($whatsappResponse !== false && $httpcode === 201) {
                        echo json_encode(['success' => true, 'message' => 'E-mail e WhatsApp enviados com sucesso.']);
                        error_log("WhatsApp enviado com sucesso para $phoneNumber com link de login.");
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Erro ao enviar mensagem no WhatsApp.']);
                        error_log("Erro ao enviar WhatsApp para $phoneNumber: " . print_r($whatsappResponse, true));
                    }

                } else {
                    echo json_encode(['success' => false, 'message' => 'Erro ao enviar e-mail usando template WHMCS.']);
                    error_log("Erro ao enviar e-mail usando template WHMCS: " . print_r($sendEmail, true));
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Erro ao criar o token.']);
                error_log("Erro ao criar o token");
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
            error_log("Cliente não encontrado para o e-mail $email");
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'E-mail não informado.']);
        error_log("E-mail não informado");
    }
    exit;
} else {
    // Retorna um erro 405 para métodos não permitidos
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido']);
    exit;
}