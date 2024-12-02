<?php

if (!defined("WHMCS")) die("Acesso restrito.");

use WHMCS\Database\Capsule;

/**
 * Envia uma mensagem de WhatsApp para o número configurado.
 *
 * @param string $number O número de telefone para enviar a mensagem.
 * @param string $text O conteúdo da mensagem a ser enviada.
 */
function enviarMensagemWhatsAppAffiliate($number, $text) {
    
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
    $body = [
        "number" => $number,
        "text" => $text,
        "linkPreview" => false
    ];

    // Log de debug - dados da mensagem
    error_log("Preparando envio de mensagem para número '$number' com dados: " . json_encode($body));

    // Inicializa o cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // Execução da solicitação
    $response = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    // Verificação e log de erro
    if ($response === false) {
        error_log("Erro no envio da mensagem. Erro: $error");
    } else {
        error_log("Mensagem enviada com sucesso. Código HTTP: $httpcode, Resposta: $response");
    }

    // Fecha o cURL
    curl_close($ch);
}

/**
 * Hook para AffiliateWithdrawalRequest para enviar notificação de solicitação de retirada.
 */
add_hook('AffiliateWithdrawalRequest', 1, function($vars) {
    // Log detalhado do conteúdo da variável $vars para depuração
    error_log("Hook 'AffiliateWithdrawalRequest' ativado. Dados do Hook: " . print_r($vars, true));

    $affiliateId = $vars['affiliateId'] ?? 'Desconhecido';
    $userId = $vars['userId'] ?? 'Desconhecido';
    $clientId = $vars['clientId'] ?? 'Desconhecido';
    $balanceFormatado = $vars['balance'] ?? 'Desconhecido';
    $balance = number_format($balanceFormatado, 2, ',', '.');

    // Obter informações do afiliado
    $user = Capsule::table('tblclients')->where('id', $userId)->first();
    $firstName = $user->firstname ?? 'Nome Desconhecido';
    $email = $user->email ?? 'E-mail Desconhecido';
    $phoneNumber = $user->phonenumber ?? null;
    $phoneNumberFormatted = processarNumeroTelefone($phoneNumber);

    // Mensagem para o administrador
    $adminText = "*Notificação de Solicitação de Retirada de Afiliado* \n\n" .
                 "🔔 Um afiliado solicitou retirada de comissão.\n" .
                 "🆔 ID do Afiliado: $affiliateId\n" .
                 "👤 Nome: $firstName\n" .
                 "📧 E-mail: $email\n" .
                 "👥 ID do Cliente: $clientId\n" .
                 "💰 Saldo da Conta: R$ $balance\n\n" .
                 "_Equipe Hostbraza_";

    // Envia a mensagem para o administrador
    enviarMensagemWhatsAppAffiliate('5561995940410', $adminText);

    // Mensagem para o afiliado, caso o número de telefone esteja disponível
    if ($phoneNumberFormatted) {
        $affiliateText = "*Solicitação de Retirada Recebida* \n\n" .
                         "Olá $firstName,\n\n" .
                         "🔔 Recebemos sua solicitação de retirada de comissão no valor de R$ $balance. " .
                         "Nossa equipe está analisando e em breve você será notificado sobre o andamento.\n\n" .
                         "_Equipe Hostbraza_";

        enviarMensagemWhatsAppAffiliate($phoneNumberFormatted, $affiliateText);
    } else {
        error_log("Número de telefone não encontrado para o afiliado com ID '$affiliateId'.");
    }
});