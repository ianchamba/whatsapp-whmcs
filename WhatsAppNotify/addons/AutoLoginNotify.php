<?php

use WHMCS\Database\Capsule;

function sendWhatsappMessage($phoneNumber, $firstName, $loginLink) {
    
    // Recupera as configurações do módulo
    $settings = Capsule::table('tbladdonmodules')
        ->where('module', 'WhatsAppNotify')
        ->pluck('value', 'setting');

    // Configurações dinâmicas
    $apiKey = $settings['apiKey'] ?? 'default_api_key';
    $apiDomain = $settings['apiDomain'] ?? 'api.example.com';
    $whatsAppInstance = $settings['whatsAppInstance'] ?? 'DefaultInstance';

    // URL da API
    
    $url = "https://$apiDomain/message/sendText/$whatsAppInstance";
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $apiKey
    ];

    $text = "*Acesso Rápido* ️\n\n" .
        "Olá {$firstName}, tudo bem? ☺️\n\n" .
        "Aqui está o link para acessar sua conta rapidamente:\n\n" .
        "{$loginLink}\n\n" .
        "_Equipe Hostbraza_";

    $body = [
        "number" => processarNumeroTelefone($phoneNumber),
        "text" => $text,
        "linkPreview" => false
    ];

    // Inicializa o cURL para enviar a mensagem pelo WhatsApp
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Executa a solicitação e captura a resposta
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpcode === 201) {
        return ['success' => true];
    } else {
        return ['success' => false, 'error' => $response];
    }
}