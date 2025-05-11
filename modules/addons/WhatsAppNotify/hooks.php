<?php

if (!defined('WHMCS')) {
    die('Access Denied');
}

use WHMCS\Database\Capsule;

class WhatsAppNotifier
{
    private string $apiKey;
    private string $apiDomain;
    private string $instance;

    public function __construct()
    {
        $settings = Capsule::table('tbladdonmodules')
            ->where('module', 'WhatsAppNotify')
            ->pluck('value', 'setting');
        $this->apiKey    = $settings['apiKey'] ?? '';
        $this->apiDomain = $settings['apiDomain'] ?? '';
        $this->instance  = $settings['whatsAppInstance'] ?? '';
    }

    private function processPhone(string $phone): string
    {
        return str_replace(['+', '.'], '', $phone);
    }

    public function sendMessage(string $number, string $text, int $delay = 0): void
    {
        $url = "https://{$this->apiDomain}/message/sendText/{$this->instance}";
        $payload = [
            'number'      => $this->processPhone($number),
            'text'        => $text,
            'linkPreview' => false,
        ];
        if ($delay > 0) {
            $payload['delay'] = $delay;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'apikey: ' . $this->apiKey],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    private function getTemplate(string $event): string
    {
        $custom = Capsule::table('tbladdonwhatsapp')
            ->where('event', $event)
            ->value('template');
        return $custom ?: '';
    }

    private function formatPaymentMethod(string $method): string
    {
        return match ($method) {
            'openpix'         => 'Pix',
            'mercadopago_1'   => 'MercadoPago',
            'stripe'          => 'Cartão de Crédito/Débito',
            default           => $method,
        };
    }

    private function populate(string $template, array $invoice, array $client): string
    {
        $products = array_map(function ($item) {
            return trim(preg_replace('/\s*\(\d{2}\/\d{2}\/\d{4}\).*/', '', $item['description']));
        }, $invoice['items']['item']);

        $placeholders = [
            '{primeiro_nome}'    => trim($client['firstname']),
            '{id_fatura}'        => $invoice['invoiceid'],
            '{metodo_pagamento}' => $this->formatPaymentMethod($invoice['paymentmethod']),
            '{valor}'            => number_format($invoice['total'], 2, ',', '.'),
            '{data_geracao}'     => (new DateTime($invoice['date']))->format('d/m/Y'),
            '{data_vencimento}'  => (new DateTime($invoice['duedate']))->format('d/m/Y'),
            '{produtos_lista}'   => implode(', ', $products),
            '{link_fatura}'      => function_exists('gerarLinkAutoLogin')
                ? gerarLinkAutoLogin($client['id'], 'clientarea', "viewinvoice.php?id={$invoice['invoiceid']}")
                : ''
        ];

        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    public function handleInvoiceCreation(array $vars): void
    {
        $inv = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
        $cli = localAPI('GetClientsDetails', ['clientid' => $inv['userid'], 'stats' => true]);
        $text = $this->populate($this->getTemplate('InvoiceCreation'), $inv, $cli);
        $this->sendMessage($cli['phonenumberformatted'], $text);
    }

    public function handleOpenpixInvoiceGenerated(array $vars): void
    {
        $id = $vars['invoiceId'];
        $record = Capsule::table('tblinvoices')->where('id', $id)->first();
        if ($record->processed) {
            return;
        }
        $inv = localAPI('GetInvoice', ['invoiceid' => $id]);
        $cli = localAPI('GetClientsDetails', ['clientid' => $inv['userid'], 'stats' => true]);
        $brCode = null;
        for ($i = 0; $i < 5 && !$brCode; $i++) {
            $row = Capsule::table('tblinvoices')->where('id', $id)->first();
            $brCode = $row->brCode ?? null;
            if (!$brCode) {
                sleep(1);
            }
        }
        if ($brCode) {
            $this->sendMessage($cli['phonenumberformatted'], "*Copie o código Pix abaixo para efetuar o pagamento:* 👇");
            $this->sendMessage($cli['phonenumberformatted'], $brCode, 3000);
        }
        Capsule::table('tblinvoices')->where('id', $id)->update(['processed' => 1]);
    }

    public function handleInvoicePaid(array $vars): void
    {
        $inv = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
        $cli = localAPI('GetClientsDetails', ['clientid' => $inv['userid'], 'stats' => true]);
        $text = $this->populate($this->getTemplate('InvoicePaid'), $inv, $cli);
        $this->sendMessage($cli['phonenumberformatted'], $text);
    }

    public function handleInvoiceCancelled(array $vars): void
    {
        $inv = localAPI('GetInvoice', ['invoiceid' => $vars['invoiceid']]);
        $cli = localAPI('GetClientsDetails', ['clientid' => $inv['userid'], 'stats' => true]);
        $text = $this->populate($this->getTemplate('InvoiceCancelled'), $inv, $cli);
        $this->sendMessage($cli['phonenumberformatted'], $text);
    }

    public function handleInvoicePaymentReminder(array $vars): void
    {
        $id = $vars['invoiceid'];
        try {
            $block = Capsule::table('tbltransientdata')
                ->where('name', "block_invoice_{$id}")
                ->where('expires', '>', date('Y-m-d H:i:s'))
                ->first();
            if ($block) {
                return;
            }
        } catch (\Throwable $e) {
        }
        $inv = localAPI('GetInvoice', ['invoiceid' => $id]);
        $cli = localAPI('GetClientsDetails', ['clientid' => $inv['userid'], 'stats' => true]);
        $event = date('Y-m-d') > $inv['duedate']
            ? 'LateInvoicePaymentReminder'
            : 'InvoicePaymentReminder';
        $text = $this->populate($this->getTemplate($event), $inv, $cli);
        $this->sendMessage($cli['phonenumberformatted'], $text);
    }

    public function handleLogTransaction(array $vars): void
    {
        if (isset($vars['result']) && strtolower($vars['result']) === 'declined'
            && preg_match('/Invoice ID\s*=>\s*(\d+)/i', $vars['data'], $m)
        ) {
            $id = $m[1];
            $inv = localAPI('GetInvoice', ['invoiceid' => $id]);
            $cli = localAPI('GetClientsDetails', ['clientid' => $inv['userid'], 'stats' => true]);
            $text = $this->populate($this->getTemplate('InvoiceDeclined'), $inv, $cli);
            $this->sendMessage($cli['phonenumberformatted'], $text);
            $name    = "block_invoice_{$id}";
            $expires = date('Y-m-d H:i:s', time() + 300);
            if (Capsule::table('tbltransientdata')->where('name', $name)->exists()) {
                Capsule::table('tbltransientdata')
                    ->where('name', $name)
                    ->update(['data' => json_encode(['blocked' => true]), 'expires' => $expires]);
            } else {
                Capsule::table('tbltransientdata')
                    ->insert(['name' => $name, 'data' => json_encode(['blocked' => true]), 'expires' => $expires]);
            }
        }
    }

    public function handleAffiliateWithdrawalRequest(array $vars): void
    {
        $affId    = $vars['affiliateId'] ?? '';
        $userId   = $vars['userId'] ?? '';
        $clientId = $vars['clientId'] ?? '';
        $balance  = number_format($vars['balance'] ?? 0, 2, ',', '.');
        $user     = Capsule::table('tblclients')->where('id', $userId)->first();
        $name     = trim($user->firstname ?? '');
        $email    = $user->email ?? '';
        $phone    = $user->phonenumber ?? '';
        $phoneFmt = $this->processPhone($phone);
        
        // Obter templates do banco de dados
        $textAdmin = $this->getTemplate('AffiliateWithdrawalAdmin');
        if (empty($textAdmin)) {
            $textAdmin = "*Notificação de Solicitação de Retirada de Afiliado*\n\n"
                . "🔔 Um afiliado solicitou retirada de comissão.\n"
                . "🆔 ID do Afiliado: {affId}\n"
                . "👤 Nome: {name}\n"
                . "📧 E-mail: {email}\n"
                . "👥 ID do Cliente: {clientId}\n"
                . "💰 Saldo da Conta: R$ {balance}\n\n"
                . "_Equipe Hostbraza_";
        }

        // Substituir placeholders
        $placeholders = [
            '{affId}' => $affId,
            '{name}' => $name,
            '{email}' => $email,
            '{clientId}' => $clientId,
            '{balance}' => $balance,
        ];
        $textAdmin = str_replace(array_keys($placeholders), array_values($placeholders), $textAdmin);
        
        $this->sendMessage('5561995940410', $textAdmin);
        
        if ($phoneFmt) {
            $textAff = $this->getTemplate('AffiliateWithdrawalClient');
            if (empty($textAff)) {
                $textAff = "*Solicitação de Retirada Recebida*\n\n"
                    . "Olá {name},\n\n"
                    . "🔔 Recebemos sua solicitação de retirada de comissão no valor de R$ {balance}. "
                    . "Nossa equipe está analisando e em breve você será notificado sobre o andamento.\n\n"
                    . "_Equipe Hostbraza_";
            }
            
            // Substituir placeholders
            $textAff = str_replace(array_keys($placeholders), array_values($placeholders), $textAff);
            
            $this->sendMessage($phoneFmt, $textAff);
        }
    }

    public function handleClientAdd(array $vars): void
    {
        $clientId = $vars['client_id'] ?? $vars['userid'] ?? null;
        $name     = trim($vars['firstname'] ?? '');
        $phone    = $vars['phonenumber'] ?? '';
        $phoneFmt = $this->processPhone($phone);
        if (!$clientId || !$phoneFmt) {
            return;
        }

        $pdo  = Capsule::connection()->getPdo();
        $stmt = $pdo->prepare("SELECT email_verification_token, email_verified_at FROM tblusers WHERE id = ?");
        $stmt->execute([$clientId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Obter template do banco de dados
        $text = $this->getTemplate('ClientAdd');
        if (empty($text)) {
            $text = "🔔 *Bem-vindo à Hostbraza, {name}!* 🔔\n\n"
                . "Obrigado por escolher a nossa hospedagem especializada em *WordPress*, *WooCommerce* e *VPS*.\n\n"
                . "📲 *Salve este número* — este é nosso canal oficial para *suporte* e *notificações de pagamento*.\n\n";
            if ($user && empty($user['email_verified_at']) && !empty($user['email_verification_token'])) {
                $text .= "🔐 *Antes de começar, verifique sua conta clicando no link abaixo:*\n{verification_link}\n\n";
            }
            $text .= "_Equipe Hostbraza_";
        }
        
        // Substituir placeholders
        $verificationLink = '';
        if ($user && empty($user['email_verified_at']) && !empty($user['email_verification_token'])) {
            $verificationLink = "https://app.hostbraza.com.br/user/verify/{$user['email_verification_token']}";
        }
        
        $placeholders = [
            '{name}' => $name,
            '{clientId}' => $clientId,
            '{verification_link}' => $verificationLink,
        ];
        $text = str_replace(array_keys($placeholders), array_values($placeholders), $text);
        
        // Remover linha de verificação se não houver link
        if (empty($verificationLink)) {
            $text = preg_replace('/🔐.*\n\n/s', '', $text);
        }
        
        $this->sendMessage($phoneFmt, $text);
    }

    /**
     * Método para enviar mensagens em massa
     * 
     * @param int $campaignId ID da campanha
     * @return void
     */
    public function sendBulkMessages($campaignId)
    {
        // Obtém os dados da campanha
        $campaign = Capsule::table('tbladdonwhatsapp_campaigns')
            ->where('id', $campaignId)
            ->first();
        
        if (!$campaign || $campaign->status == 'completed' || $campaign->status == 'cancelled') {
            return;
        }
        
        // Atualiza o status para "processando"
        Capsule::table('tbladdonwhatsapp_campaigns')
            ->where('id', $campaignId)
            ->update([
                'status' => 'processing',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        // Processa mensagens em lotes para evitar timeouts
        $batchSize = 20;
        $totalProcessed = 0;
        $delay = $campaign->delay;
        
        // Obter todas as mensagens/templates da campanha
        $templates = Capsule::table('tbladdonwhatsapp_campaign_templates')
            ->where('campaign_id', $campaignId)
            ->orderBy('id')
            ->get();
        
        $templateCount = count($templates);
        $templateIndex = 0;
        
        while (true) {
            // Busca o próximo lote
            $messages = Capsule::table('tbladdonwhatsapp_messages')
                ->where('campaign_id', $campaignId)
                ->where('status', 'pending')
                ->limit($batchSize)
                ->get();
            
            if (count($messages) == 0) {
                break; // Não há mais mensagens para processar
            }
            
            foreach ($messages as $index => $message) {
                // Calcular o delay progressivo
                $calculatedDelay = $index * $delay;
                
                try {
                    // Selecionar um template para este cliente (rotação de templates)
                    $messageText = $message->message;
                    
                    // Se houver múltiplos templates, usar um diferente para cada cliente
                    if ($templateCount > 0) {
                        // Selecionar o próximo template na sequência
                        $template = $templates[$templateIndex % $templateCount];
                        $messageText = $template->message;
                        $templateIndex++;
                    }
                    
                    // Envia a mensagem com o template selecionado
                    $this->sendMessage($message->phone, $messageText, $calculatedDelay);
                    
                    // Atualiza o status da mensagem
                    Capsule::table('tbladdonwhatsapp_messages')
                        ->where('id', $message->id)
                        ->update([
                            'status' => 'sent',
                            'sent_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                    
                    $totalProcessed++;
                } catch (\Exception $e) {
                    // Registra o erro
                    Capsule::table('tbladdonwhatsapp_messages')
                        ->where('id', $message->id)
                        ->update([
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                            'updated_at' => date('Y-m-d H:i:s'),
                        ]);
                }
            }
            
            // Atualiza o progresso da campanha
            Capsule::table('tbladdonwhatsapp_campaigns')
                ->where('id', $campaignId)
                ->update([
                    'sent_count' => Capsule::raw('sent_count + ' . count($messages)),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            
            // Verifica se deve parar
            if (count($messages) < $batchSize) {
                break;
            }
            
            // Pausa entre lotes para evitar sobrecarga
            sleep(2);
        }
        
        // Marca a campanha como concluída
        Capsule::table('tbladdonwhatsapp_campaigns')
            ->where('id', $campaignId)
            ->update([
                'status' => 'completed',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}

$notifier = new WhatsAppNotifier();

add_hook('InvoiceCreation', 1, [$notifier, 'handleInvoiceCreation']);
add_hook('OpenpixInvoiceGenerated', 1, [$notifier, 'handleOpenpixInvoiceGenerated']);
add_hook('InvoicePaid', 1, [$notifier, 'handleInvoicePaid']);
add_hook('InvoiceCancelled', 1, [$notifier, 'handleInvoiceCancelled']);
add_hook('InvoicePaymentReminder', 1, [$notifier, 'handleInvoicePaymentReminder']);
add_hook('LogTransaction', 1, [$notifier, 'handleLogTransaction']);
add_hook('AffiliateWithdrawalRequest', 1, [$notifier, 'handleAffiliateWithdrawalRequest']);
add_hook('ClientAdd', 1, [$notifier, 'handleClientAdd']);