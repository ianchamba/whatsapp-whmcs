<?php

if (!defined("WHMCS")) die("Acesso restrito.");

use WHMCS\Database\Capsule;

/**
 * Processa o número de telefone para garantir que ele siga o formato correto.
 *
 * @param string $phonenumber O número de telefone no formato original.
 * @return string O número de telefone processado.
 */
function processarNumeroTelefone($phonenumber) {
    // Remove caracteres '+' e '.'
    return str_replace(['+', '.'], '', $phonenumber);
}

/**
 * Envia uma mensagem no WhatsApp usando a API especificada com dados da fatura e do cliente.
 *
 * @param string $event O nome do evento (InvoiceCreation, InvoicePaid, InvoiceCancelled).
 * @param array $invoiceData Dados da fatura a serem enviados na mensagem.
 * @param array $clientData Dados do cliente a serem enviados na mensagem.
 */
// Função de envio de mensagem no WhatsApp com link de autologin
function enviarMensagemWhatsApp($event, $invoiceData, $clientData) {
    // Processa o número de telefone
    $number = processarNumeroTelefone($clientData['phonenumberformatted']);
    $valorFormatado = number_format($invoiceData['total'], 2, ',', '.');
    $dateFormatted = DateTime::createFromFormat('Y-m-d', $invoiceData['date'])->format('d/m/Y');
    $dueDateFormatted = DateTime::createFromFormat('Y-m-d', $invoiceData['duedate'])->format('d/m/Y');
    $metodoPagamento = $invoiceData['paymentmethod'] === 'openpix' 
    ? 'Pix' 
    : ($invoiceData['paymentmethod'] === 'mercadopago_1' 
        ? 'MercadoPago' 
        : $invoiceData['paymentmethod']);
    
    // Obtém a lista de produtos (somente o nome)
    $produtos = array_map(function($item) {
        return trim(preg_replace('/\s*\(\d{2}\/\d{2}\/\d{4} - \d{2}\/\d{2}\/\d{4}\)(.|\s)*/', '', $item['description']));
    }, $invoiceData['items']['item']);
    $produtosLista = implode(", ", $produtos);

    if ($invoiceData['status'] === 'Unpaid') {
        // Caso padrão para fatura em aberto, sem Pix
        $text = "*Fatura em Aberto* \n\n".
                "Olá {$clientData['firstname']}, tudo bem? ☺️\n\n".
                "🔖 Você possui uma fatura em aberto #{$invoiceData['invoiceid']}.\n".
                "💵 Valor: R$ $valorFormatado\n".
                "💳 Método de Pagamento: $metodoPagamento\n".
                "📦 Produtos: $produtosLista\n\n".
                "Para acessar sua fatura, clique no link abaixo:\n\n".
                gerarLinkAutoLogin($clientData['id'], 'clientarea', "viewinvoice.php?id={$invoiceData['invoiceid']}") . "\n\n" .
                "_Equipe Hostbraza_";
    } elseif ($invoiceData['status'] === 'Paid') {
        $text = "*Fatura Paga* \n\n".
                "Olá {$clientData['firstname']}, tudo bem? ☺️\n\n".
                "✅ Sua fatura #{$invoiceData['invoiceid']} foi paga com sucesso.\n".
                "💵 Valor: R$ $valorFormatado\n".
                "💳 Método de Pagamento: $metodoPagamento\n".
                "📦 Produtos: $produtosLista\n\n".
                "Obrigado por escolher nossos serviços! Estamos sempre à disposição. 💙" . "\n\n" .
                "_Equipe Hostbraza_";
    } elseif ($event === 'InvoicePaymentReminder') {
        $text = "*Lembrete de Pagamento* \n\n".
                "Olá {$clientData['firstname']}, tudo bem? ☺️\n\n".
                "Esta mensagem é apenas um lembrete referente a fatura #{$invoiceData['invoiceid']} gerada em $dateFormatted com vencimento $dueDateFormatted.\n".
                "💵 Valor: R$ $valorFormatado\n".
                "💳 Método de Pagamento: $metodoPagamento\n".
                "📦 Produtos: $produtosLista\n\n".
                "Para acessar sua fatura, clique no link abaixo:\n\n".
                gerarLinkAutoLogin($clientData['id'], 'clientarea', "viewinvoice.php?id={$invoiceData['invoiceid']}") . "\n\n" .
                "_Equipe Hostbraza_";
    } elseif ($invoiceData['status'] === 'Cancelled') {
        $text = "*Fatura Cancelada* \n\n".
                "Olá {$clientData['firstname']}, tudo bem? ☺️\n\n".
                "⚠️ Informamos que a fatura #{$invoiceData['invoiceid']} foi cancelada.\n".
                "💵 Valor: R$ $valorFormatado\n".
                "💳 Método de Pagamento: $metodoPagamento\n".
                "📦 Produtos: $produtosLista\n\n".
                "Se precisar de mais informações ou ajuda, estamos à disposição. 💙" . "\n\n" .
                "_Equipe Hostbraza_";
    } else {
        $text = "*Status da Fatura* \n\n".
                "Olá {$clientData['firstname']}, tudo bem? ☺️\n\n".
                "🔖 Status da sua fatura #{$invoiceData['invoiceid']}: {$invoiceData['status']}\n".
                "💵 Valor: R$ $valorFormatado\n".
                "💳 Método de Pagamento: $metodoPagamento\n".
                "📦 Produtos: $produtosLista\n\n".
                "Se precisar de mais informações ou ajuda, clique no link abaixo:\n\n".
                gerarLinkAutoLogin($clientData['id'], 'clientarea', "viewinvoice.php?id={$invoiceData['invoiceid']}") . "\n\n" .
                "_Equipe Hostbraza_";
    }

    $url = "https://seudominio/message/sendText/Hostbraza";
    $headers = [
        'Content-Type: application/json',
        'apikey: SUA_API_KEY'
    ];
    $body = [
        "number" => $number,
        "text" => $text,
        "linkPreview" => false
    ];

    // Log de debug - dados da mensagem
    error_log("Preparando envio de mensagem para o evento '$event' com número '$number' e dados: " . json_encode($body));

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
        error_log("Erro no envio da mensagem para o evento '$event'. Erro: $error");
    } else {
        error_log("Mensagem enviada com sucesso para o evento '$event'. Código HTTP: $httpcode, Resposta: $response");
    }

    // Fecha o cURL
    curl_close($ch);
}

function enviarMensagemPix($event, $invoiceData, $clientData) {
    // Processa o número de telefone
    $number = processarNumeroTelefone($clientData['phonenumberformatted']);

    // Define o conteúdo da mensagem conforme o status da fatura
    if ($invoiceData['paymentmethod'] === 'openpix' && $invoiceData['status'] === 'Unpaid') {
        
        // Tentativas para obter o brCode
        $tentativas = 0;
        $maxTentativas = 5;
        $brCode = null;

        while ($tentativas < $maxTentativas && !$brCode) {
            // Busca o registro da fatura e acessa a coluna 'brCode'
            $invoice = Capsule::table('tblinvoices')
                ->where('id', $invoiceData['invoiceid'])
                ->first();
                error_log("Metadata Pix: " . print_r($invoice, true));
            
            if ($invoice && isset($invoice->brCode)) {
                $brCode = $invoice->brCode;
                error_log("brCode encontrado: $brCode");
            } else {
                $brCode = null;
                error_log("brCode não encontrado na tentativa " . ($tentativas + 1) . ". Retentando em 1 segundo...");
                sleep(1);
            }

            $tentativas++;
        }

        if (!$brCode) {
            $text = "*Código Pix Não Encontrado*";
            error_log("Falha ao encontrar brCode após $maxTentativas tentativas.");
        } else {
            $text = "*Copie o código Pix abaixo para efetuar o pagamento:* \n\n" . $brCode;
        }

        $url = "https://seudominio/message/sendText/Hostbraza";
        $headers = [
            'Content-Type: application/json',
            'apikey: SUA_API_KEY'
        ];
        $body = [
            "number" => $number,
            "text" => $text,
            "linkPreview" => false
        ];

        // Log de debug - dados da mensagem
        error_log("Preparando envio de mensagem para o evento '$event' com número '$number' e dados: " . json_encode($body));

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
            error_log("Erro no envio da mensagem para o evento '$event'. Erro: $error");
        } else {
            error_log("Mensagem enviada com sucesso para o evento '$event'. Código HTTP: $httpcode, Resposta: $response");
        }

        // Fecha o cURL
        curl_close($ch);
    }
}

// Hook para InvoiceCreation
add_hook('InvoiceCreation', 1, function($vars) {
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
    $clientData = localAPI('GetClientsDetails', ['clientid' => $invoiceData['userid'], 'stats' => true]);
    error_log("Hook 'InvoiceCreation' ativado. Dados da Fatura: " . print_r($invoiceData, true) . " Dados do Cliente: " . print_r($clientData, true));
    enviarMensagemWhatsApp('InvoiceCreation', $invoiceData, $clientData);
});

add_hook('OpenpixInvoiceGenerated', 1, function($vars) {
    $invoiceId = $vars['invoiceId'];

    // Verifica se o invoice já foi processado
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoice && $invoice->processed) {
        error_log("Invoice ID $invoiceId já foi processado. Ignorando.");
        return;
    }

    // Obtenha os dados da fatura e do cliente e envie a mensagem
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $clientData = localAPI('GetClientsDetails', ['clientid' => $invoiceData['userid'], 'stats' => true]);
    error_log("Hook 'OpenpixInvoiceGenerated' triggered. Invoice Data: " . print_r($invoiceData, true) . " Client Data: " . print_r($clientData, true));
    enviarMensagemPix('OpenpixInvoiceGenerated', $invoiceData, $clientData);

    // Atualiza o status de processamento no banco de dados
    Capsule::table('tblinvoices')->where('id', $invoiceId)->update(['processed' => 1]);
});

// Hook para InvoicePaid
add_hook('InvoicePaid', 1, function($vars) {
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
    $clientData = localAPI('GetClientsDetails', ['clientid' => $invoiceData['userid'], 'stats' => true]);
    error_log("Hook 'InvoicePaid' ativado. Dados da Fatura: " . print_r($invoiceData, true) . " Dados do Cliente: " . print_r($clientData, true));
    enviarMensagemWhatsApp('InvoicePaid', $invoiceData, $clientData);
});

// Hook para InvoiceCancelled
add_hook('InvoiceCancelled', 1, function($vars) {
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
    $clientData = localAPI('GetClientsDetails', ['clientid' => $invoiceData['userid'], 'stats' => true]);
    error_log("Hook 'InvoiceCancelled' ativado. Dados da Fatura: " . print_r($invoiceData, true) . " Dados do Cliente: " . print_r($clientData, true));
    enviarMensagemWhatsApp('InvoiceCancelled', $invoiceData, $clientData);
});

// Hook para InvoicePaymentReminder
add_hook('InvoicePaymentReminder', 1, function($vars) {
    $invoiceData = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
    $clientData = localAPI('GetClientsDetails', ['clientid' => $invoiceData['userid'], 'stats' => true]);
    error_log("Hook 'InvoicePaymentReminder' ativado. Dados da Fatura: " . print_r($invoiceData, true) . " Dados do Cliente: " . print_r($clientData, true));
    enviarMensagemWhatsApp('InvoicePaymentReminder', $invoiceData, $clientData);
});