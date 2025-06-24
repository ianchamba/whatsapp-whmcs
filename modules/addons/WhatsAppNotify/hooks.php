<?php

if (!defined("WHMCS")) {
    die("Access Denied");
}

use WHMCS\Database\Capsule;

class WhatsAppNotifier
{
    private string $apiKey;
    private string $apiDomain;
    private string $instance;

    // Constantes para o status de processamento
    const STATUS_PENDING = "pending"; // Ainda não iniciado
    const STATUS_READY = "ready"; // Pronto para ser processado pelo cron
    const STATUS_PROCESSING = "processing"; // Em processamento
    const STATUS_PAUSED = "paused"; // Pausado manualmente
    const STATUS_COMPLETED = "completed"; // Concluído
    const STATUS_CANCELLED = "cancelled"; // Cancelado
    const STATUS_SCHEDULED = "scheduled"; // Agendado para envio futuro

    public function __construct()
    {
        $settings = Capsule::table("tbladdonmodules")
            ->where("module", "WhatsAppNotify")
            ->pluck("value", "setting");
        $this->apiKey = $settings["apiKey"] ?? "";
        $this->apiDomain = $settings["apiDomain"] ?? "";
        $this->instance = $settings["whatsAppInstance"] ?? "";
    }

    private function processPhone(string $phone): string
    {
        return str_replace(["+", "."], "", $phone);
    }

    /**
     * Envia uma mensagem pelo WhatsApp
     *
     * @param string $number Número de telefone destinatário
     * @param string $text Texto da mensagem
     * @param int $delay Delay em milissegundos
     * @return array Resposta da API
     */
    public function sendMessage(
        string $number,
        string $text,
        int $delay = 0
    ): array {
        $url = "https://{$this->apiDomain}/message/sendText/{$this->instance}";
        $payload = [
            "number" => $this->processPhone($number),
            "text" => $text,
            "linkPreview" => false,
        ];
        if ($delay > 0) {
            $payload["delay"] = $delay;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
                "apikey: " . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60, // Aumentado para 60 segundos
            CURLOPT_CONNECTTIMEOUT => 20, // Timeout de conexão 20 segundos
            CURLOPT_SSL_VERIFYPEER => false, // Desabilitar verificação SSL se necessário
            CURLOPT_SSL_VERIFYHOST => 0, // Desabilitar verificação do host SSL
            CURLOPT_FORBID_REUSE => true, // Evitar reuso de conexão que pode estar com problemas
            CURLOPT_FRESH_CONNECT => true, // Forçar nova conexão
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Log da resposta para fins de depuração
        logActivity(
            "WhatsApp enviado para $number - Status: $httpCode - Resposta: " .
                substr($response, 0, 100)
        );

        if ($error) {
            throw new Exception("Erro ao enviar mensagem: $error");
        }

        $responseData = json_decode($response, true);
        if (!$responseData) {
            throw new Exception("Resposta inválida da API: $response");
        }

        return $responseData;
    }

    private function getTemplate(string $event): string
    {
        $custom = Capsule::table("tbladdonwhatsapp")
            ->where("event", $event)
            ->value("template");
        return $custom ?: "";
    }

    private function formatPaymentMethod(string $method): string
    {
        return match ($method) {
            "openpix" => "Pix",
            "mercadopago_1" => "MercadoPago",
            "stripe" => "Cartão de Crédito/Débito",
            "paypal_ppcpv" => "PayPal",
            "blockonomics" => "Bitcoin",
            default => $method,
        };
    }

    private function populate(
        string $template,
        array $invoice,
        array $client
    ): string {
        $products = [];
        $addons = [];

        try {
            $productNames = $this->getProductNames();
            $addonNames = $this->getAddonNames();

            $items = [];
            if (
                isset($invoice["items"]["item"]) &&
                is_array($invoice["items"]["item"])
            ) {
                $items = $invoice["items"]["item"];
            } elseif (isset($invoice["items"]) && is_array($invoice["items"])) {
                $items = $invoice["items"];
            }

            foreach ($items as $item) {
                $description = $item["description"] ?? "";

                // NOVA REGRA: Upgrade/Downgrade
                if (strpos($description, "Upgrade/Downgrade") !== false) {
                    // Extrai a parte entre ":" e as datas em parênteses
                    $pattern =
                        "/Upgrade\/Downgrade:\s*(.+?)\s*\(\d{2}\/\d{2}\/\d{4}\s*-\s*\d{2}\/\d{2}\/\d{4}\)/";
                    if (preg_match($pattern, $description, $matches)) {
                        $extractedPart = trim($matches[1]);
                        // Remove o domínio (qualquer coisa que termine com .com.br, .com, etc)
                        $cleanPart = preg_replace(
                            "/\s*-\s*[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}\s*/",
                            " ",
                            $extractedPart
                        );
                        $cleanPart = preg_replace(
                            "/\s+/",
                            " ",
                            trim($cleanPart)
                        ); // Remove espaços extras
                        $products[] = "Upgrade/Downgrade - " . $cleanPart;
                    } else {
                        // Fallback caso a regex não funcione
                        $products[] = "Upgrade/Downgrade";
                    }
                    continue;
                }

                // Regra existente: Registro de domínio
                if (strpos($description, "Registro de domínio") !== false) {
                    $cleanDescription = trim(
                        preg_replace(
                            "/\s*\(\d{2}\/\d{2}\/\d{4}\s*-\s*\d{2}\/\d{2}\/\d{4}\)/",
                            "",
                            $description
                        )
                    );
                    $products[] = $cleanDescription;
                    continue;
                }

                // Regra existente: Adicionais
                if (strpos($description, "Adicionais") !== false) {
                    foreach ($addonNames as $addonName) {
                        if (strpos($description, $addonName) !== false) {
                            $addons[] = $addonName;
                            break;
                        }
                    }
                    continue;
                }

                // Regra existente: Produtos genéricos
                foreach ($productNames as $productName) {
                    if (strpos($description, $productName) !== false) {
                        $products[] = $productName;
                        break;
                    }
                }
            }

            if (empty($products) && empty($addons)) {
                logActivity(
                    "WhatsApp Debug: Nenhum produto/adicional encontrado na fatura {$invoice["invoiceid"]}. Estrutura items: " .
                        json_encode($invoice["items"] ?? "não definido")
                );
            }
        } catch (\Exception $e) {
            logActivity(
                "Erro ao processar produtos da fatura {$invoice["invoiceid"]}: " .
                    $e->getMessage()
            );
            $products = ["Produto não identificado"];
            $addons = [];
        }

        $products = array_unique($products);
        $addons = array_unique($addons);

        $placeholders = [
            "{primeiro_nome}" => trim($client["firstname"]),
            "{id_fatura}" => $invoice["invoiceid"],
            "{metodo_pagamento}" => $this->formatPaymentMethod(
                $invoice["paymentmethod"]
            ),
            "{valor}" => number_format($invoice["total"], 2, ",", "."),
            "{data_geracao}" => (new DateTime($invoice["date"]))->format(
                "d/m/Y"
            ),
            "{data_vencimento}" => (new DateTime($invoice["duedate"]))->format(
                "d/m/Y"
            ),
            "{produtos_lista}" => !empty($products)
                ? implode(", ", $products)
                : "Nenhum produto encontrado",
            "{adicionais_lista}" => !empty($addons)
                ? implode(", ", $addons)
                : "Sem adicionais",
            "{link_fatura}" => function_exists("gerarLinkAutoLogin")
                ? gerarLinkAutoLogin(
                    $client["id"],
                    "clientarea",
                    "viewinvoice.php?id={$invoice["invoiceid"]}"
                )
                : "",
        ];

        return str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $template
        );
    }

    private function getProductNames(): array
    {
        try {
            $result = full_query(
                "SELECT name FROM tblproducts WHERE hidden = 0"
            );
            $products = [];

            while ($row = mysql_fetch_assoc($result)) {
                $products[] = $row["name"];
            }

            return $products;
        } catch (\Exception $e) {
            logActivity(
                "Erro ao buscar nomes de produtos: " . $e->getMessage()
            );
            return [];
        }
    }

    private function getAddonNames(): array
    {
        try {
            $result = full_query("SELECT name FROM tbladdons WHERE hidden = 0");
            $addons = [];

            while ($row = mysql_fetch_assoc($result)) {
                $addons[] = $row["name"];
            }

            return $addons;
        } catch (\Exception $e) {
            logActivity(
                "Erro ao buscar nomes de adicionais: " . $e->getMessage()
            );
            return [];
        }
    }

    public function handleInvoiceCreation(array $vars): void
    {
        $inv = localAPI("GetInvoice", ["invoiceid" => $vars["invoiceid"]]);

        // Verificar status da fatura - não notificar apenas se for Draft
        $status = strtolower($inv["status"] ?? "");
        if ($status === "draft") {
            return; // Não notificar rascunhos
        }

        // Verificar valor da fatura - só notificar se for maior que zero
        $total = floatval($inv["total"] ?? 0);
        if ($total <= 0) {
            return; // Não notificar faturas com valor zero
        }

        $cli = localAPI("GetClientsDetails", [
            "clientid" => $inv["userid"],
            "stats" => true,
        ]);
        $text = $this->populate(
            $this->getTemplate("InvoiceCreation"),
            $inv,
            $cli
        );
        $this->sendMessage($cli["phonenumberformatted"], $text);
    }

    public function handleOpenpixInvoiceGenerated(array $vars): void
    {
        $id = $vars["invoiceId"];
        $record = Capsule::table("tblinvoices")
            ->where("id", $id)
            ->first();
        if ($record->processed) {
            return;
        }
        $inv = localAPI("GetInvoice", ["invoiceid" => $id]);
        $cli = localAPI("GetClientsDetails", [
            "clientid" => $inv["userid"],
            "stats" => true,
        ]);
        $brCode = null;
        for ($i = 0; $i < 5 && !$brCode; $i++) {
            $row = Capsule::table("tblinvoices")
                ->where("id", $id)
                ->first();
            $brCode = $row->brCode ?? null;
            if (!$brCode) {
                sleep(1);
            }
        }
        if ($brCode) {
            $this->sendMessage(
                $cli["phonenumberformatted"],
                "*Copie o código Pix abaixo para efetuar o pagamento:* 👇"
            );
            $this->sendMessage($cli["phonenumberformatted"], $brCode, 3000);
        }
        Capsule::table("tblinvoices")
            ->where("id", $id)
            ->update(["processed" => 1]);
    }

    public function handleInvoicePaid(array $vars): void
    {
        $inv = localAPI("GetInvoice", ["invoiceid" => $vars["invoiceid"]]);
        $cli = localAPI("GetClientsDetails", [
            "clientid" => $inv["userid"],
            "stats" => true,
        ]);
        $text = $this->populate($this->getTemplate("InvoicePaid"), $inv, $cli);
        $this->sendMessage($cli["phonenumberformatted"], $text);
    }

    public function handleInvoiceCancelled(array $vars): void
    {
        $inv = localAPI("GetInvoice", ["invoiceid" => $vars["invoiceid"]]);
        $cli = localAPI("GetClientsDetails", [
            "clientid" => $inv["userid"],
            "stats" => true,
        ]);
        $text = $this->populate(
            $this->getTemplate("InvoiceCancelled"),
            $inv,
            $cli
        );
        $this->sendMessage($cli["phonenumberformatted"], $text);
    }

    public function handleInvoicePaymentReminder(array $vars): void
    {
        $id = $vars["invoiceid"];

        try {
            // ✅ CORREÇÃO: Usar interpolação correta da variável
            $blockName = "block_invoice_$id";

            $block = Capsule::table("tbltransientdata")
                ->where("name", $blockName) // ✅ Agora usa a variável
                ->where("expires", ">", date("Y-m-d H:i:s"))
                ->first();

            if ($block) {
                // ✅ Log para debug - verificar se o bloqueio está funcionando
                logActivity(
                    "WhatsApp: Envio de lembrete bloqueado para fatura $id (cartão recusado recentemente)"
                );
                return;
            }
        } catch (\Throwable $e) {
            // ✅ Log do erro para debug
            logActivity(
                "WhatsApp: Erro ao verificar bloqueio para fatura $id: " .
                    $e->getMessage()
            );
        }

        $inv = localAPI("GetInvoice", ["invoiceid" => $id]);
        $cli = localAPI("GetClientsDetails", [
            "clientid" => $inv["userid"],
            "stats" => true,
        ]);

        $event =
            date("Y-m-d") > $inv["duedate"]
                ? "LateInvoicePaymentReminder"
                : "InvoicePaymentReminder";

        $text = $this->populate($this->getTemplate($event), $inv, $cli);
        $this->sendMessage($cli["phonenumberformatted"], $text);

        if (
            isset($inv["paymentmethod"]) &&
            $inv["paymentmethod"] === "openpix"
        ) {
            $brCode = null;
            for ($i = 0; $i < 5 && !$brCode; $i++) {
                $row = Capsule::table("tblinvoices")
                    ->where("id", $id)
                    ->first();
                $brCode = $row->brCode ?? null;
                if (!$brCode) {
                    sleep(1);
                }
            }
            if ($brCode) {
                $this->sendMessage(
                    $cli["phonenumberformatted"],
                    "*Aqui está novamente o código Pix para efetuar o pagamento:* 👇"
                );
                $this->sendMessage($cli["phonenumberformatted"], $brCode, 3000);
            }
        }
    }

    public function handleLogTransaction(array $vars): void
    {
        if (
            isset($vars["result"]) &&
            strtolower($vars["result"]) === "declined" &&
            preg_match("/Invoice ID\s*=>\s*(\d+)/i", $vars["data"], $m)
        ) {
            $id = $m[1];
            $inv = localAPI("GetInvoice", ["invoiceid" => $id]);
            $cli = localAPI("GetClientsDetails", [
                "clientid" => $inv["userid"],
                "stats" => true,
            ]);
            $text = $this->populate(
                $this->getTemplate("InvoiceDeclined"),
                $inv,
                $cli
            );
            $this->sendMessage($cli["phonenumberformatted"], $text);

            // ✅ CORREÇÃO: Usar a mesma lógica de nomeação
            $blockName = "block_invoice_$id";
            $expires = date("Y-m-d H:i:s", time() + 300);

            // ✅ Log para debug
            logActivity(
                "WhatsApp: Criando bloqueio '$blockName' até $expires para fatura $id (cartão recusado)"
            );

            if (
                Capsule::table("tbltransientdata")
                    ->where("name", $blockName)
                    ->exists()
            ) {
                Capsule::table("tbltransientdata")
                    ->where("name", $blockName)
                    ->update([
                        "data" => json_encode(["blocked" => true]),
                        "expires" => $expires,
                    ]);
            } else {
                Capsule::table("tbltransientdata")->insert([
                    "name" => $blockName,
                    "data" => json_encode(["blocked" => true]),
                    "expires" => $expires,
                ]);
            }
        }
    }

    public function handleAffiliateWithdrawalRequest(array $vars): void
    {
        $affId = $vars["affiliateId"] ?? "";
        $userId = $vars["userId"] ?? "";
        $clientId = $vars["clientId"] ?? "";
        $balance = number_format($vars["balance"] ?? 0, 2, ",", ".");
        $user = Capsule::table("tblclients")
            ->where("id", $userId)
            ->first();
        $name = trim($user->firstname ?? "");
        $email = $user->email ?? "";
        $phone = $user->phonenumber ?? "";
        $phoneFmt = $this->processPhone($phone);

        // Obter templates do banco de dados
        $textAdmin = $this->getTemplate("AffiliateWithdrawalAdmin");
        if (empty($textAdmin)) {
            $textAdmin =
                "*Notificação de Solicitação de Retirada de Afiliado*\n\n" .
                "🔔 Um afiliado solicitou retirada de comissão.\n" .
                "🆔 ID do Afiliado: {affId}\n" .
                "👤 Nome: {name}\n" .
                "📧 E-mail: {email}\n" .
                "👥 ID do Cliente: {clientId}\n" .
                "💰 Saldo da Conta: R$ {balance}\n\n" .
                "_Equipe Hostbraza_";
        }

        // Substituir placeholders
        $placeholders = [
            "{affId}" => $affId,
            "{name}" => $name,
            "{email}" => $email,
            "{clientId}" => $clientId,
            "{balance}" => $balance,
        ];
        $textAdmin = str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $textAdmin
        );

        $this->sendMessage("5561995940410", $textAdmin);

        if ($phoneFmt) {
            $textAff = $this->getTemplate("AffiliateWithdrawalClient");
            if (empty($textAff)) {
                $textAff =
                    "*Solicitação de Retirada Recebida*\n\n" .
                    "Olá {name},\n\n" .
                    "🔔 Recebemos sua solicitação de retirada de comissão no valor de R$ {balance}. " .
                    "Nossa equipe está analisando e em breve você será notificado sobre o andamento.\n\n" .
                    "_Equipe Hostbraza_";
            }

            // Substituir placeholders
            $textAff = str_replace(
                array_keys($placeholders),
                array_values($placeholders),
                $textAff
            );

            $this->sendMessage($phoneFmt, $textAff);
        }
    }

    public function handleClientAdd(array $vars): void
    {
        $userId = $vars["user_id"] ?? ($vars["userid"] ?? null);
        $name = trim($vars["firstname"] ?? "");
        $phone = $vars["phonenumber"] ?? "";
        $phoneFmt = $this->processPhone($phone);
        if (!$userId || !$phoneFmt) {
            return;
        }

        $pdo = Capsule::connection()->getPdo();
        $stmt = $pdo->prepare(
            "SELECT email_verification_token, email_verified_at FROM tblusers WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Obter template do banco de dados
        $text = $this->getTemplate("ClientAdd");
        if (empty($text)) {
            $text =
                "🔔 *Bem-vindo à Hostbraza, {name}!* 🔔\n\n" .
                "Obrigado por escolher a nossa hospedagem especializada em *WordPress*, *WooCommerce* e *VPS*.\n\n" .
                "📲 *Salve este número* — este é nosso canal oficial para *suporte* e *notificações de pagamento*.\n\n";
            if (
                $user &&
                empty($user["email_verified_at"]) &&
                !empty($user["email_verification_token"])
            ) {
                $text .=
                    "🔐 *Antes de começar, verifique sua conta clicando no link abaixo:*\n{verification_link}\n\n";
            }
            $text .= "_Equipe Hostbraza_";
        }

        // Substituir placeholders
        $verificationLink = "";
        if (
            $user &&
            empty($user["email_verified_at"]) &&
            !empty($user["email_verification_token"])
        ) {
            $verificationLink = "https://app.hostbraza.com.br/user/verify/{$user["email_verification_token"]}";
        }

        $placeholders = [
            "{name}" => $name,
            "{userId}" => $userId,
            "{verification_link}" => $verificationLink,
        ];
        $text = str_replace(
            array_keys($placeholders),
            array_values($placeholders),
            $text
        );

        // Remover linha de verificação se não houver link
        if (empty($verificationLink)) {
            $text = preg_replace('/🔐.*\n\n/s', "", $text);
        }

        $this->sendMessage($phoneFmt, $text);
    }

    /**
     * Handler para capturar e enviar código de autenticação 2FA via WhatsApp
     */
    public function handleAuthCode(array $vars): void
    {
        // Verificar se é o template de autenticação 2FA
        if (
            isset($vars["messagename"]) &&
            strpos($vars["messagename"], "SecurityBraza: Email 2FA") !== false
        ) {
            // Extrair informações das mergefields
            $mergefields = $vars["mergefields"] ?? [];
            $authCode = $mergefields["verification_code"] ?? null;
            $firstName = $mergefields["client_first_name"] ?? "";
            $userEmail = $mergefields["client_email"] ?? "";
            $relId = $vars["relid"] ?? null;

            if ($authCode && $relId) {
                try {
                    // Buscar dados do cliente/usuário
                    $cli = localAPI("GetClientsDetails", [
                        "clientid" => $relId,
                        "stats" => true,
                    ]);

                    if (
                        isset($cli["phonenumberformatted"]) &&
                        !empty($cli["phonenumberformatted"])
                    ) {
                        // Template da mensagem para o código 2FA
                        $text = $this->getTemplate("TwoFactorAuth");
                        if (empty($text)) {
                            $text =
                                "🔐 *Código de Autenticação 2FA*\n\n" .
                                "Olá {primeiro_nome},\n\n" .
                                "Seu código de autenticação de dois fatores é:\n\n" .
                                "*{auth_code}*\n\n" .
                                "⏰ Este código expira em alguns minutos.\n" .
                                "🔒 Use-o para concluir sua autenticação.\n\n" .
                                "_Equipe Hostbraza_";
                        }

                        // Substituir placeholders
                        $placeholders = [
                            "{primeiro_nome}" => $firstName,
                            "{auth_code}" => $authCode,
                            "{email}" => $userEmail,
                        ];

                        $message = str_replace(
                            array_keys($placeholders),
                            array_values($placeholders),
                            $text
                        );

                        // Enviar via WhatsApp
                        $this->sendMessage(
                            $cli["phonenumberformatted"],
                            $message
                        );

                        logActivity(
                            "Código 2FA enviado via WhatsApp para cliente ID {$relId} - Código: {$authCode}"
                        );
                    } else {
                        logActivity(
                            "Cliente ID {$relId} não possui telefone cadastrado para envio do código 2FA"
                        );
                    }
                } catch (\Exception $e) {
                    logActivity(
                        "Erro ao enviar código 2FA via WhatsApp para cliente ID {$relId}: " .
                            $e->getMessage()
                    );
                }
            }
        }
    }

    /**
     * Método para processar campanhas de envio em massa
     * Executado pelo cron do WHMCS
     */
    public function processCampaigns()
    {
        // Processa campanhas agendadas que já chegaram ao horário
        $scheduledCampaigns = Capsule::table("tbladdonwhatsapp_campaigns")
            ->where("status", self::STATUS_SCHEDULED)
            ->where("scheduled_at", "<=", date("Y-m-d H:i:s"))
            ->get();

        foreach ($scheduledCampaigns as $campaign) {
            // Atualizar status para "ready"
            Capsule::table("tbladdonwhatsapp_campaigns")
                ->where("id", $campaign->id)
                ->update([
                    "status" => self::STATUS_READY,
                    "updated_at" => date("Y-m-d H:i:s"),
                ]);

            logActivity(
                "Campanha WhatsApp ID #{$campaign->id} está pronta para processamento (agendamento atingido)"
            );
        }

        // Buscar campanhas prontas para processamento
        $readyCampaigns = Capsule::table("tbladdonwhatsapp_campaigns")
            ->where("status", self::STATUS_READY)
            ->get();

        foreach ($readyCampaigns as $campaign) {
            try {
                $this->sendBulkMessages($campaign->id);
            } catch (\Exception $e) {
                logActivity(
                    "Erro ao processar campanha #{$campaign->id}: " .
                        $e->getMessage()
                );
            }
        }

        // Retomar campanhas interrompidas (processamento interrompido há mais de 30 minutos)
        $processingStalledCampaigns = Capsule::table(
            "tbladdonwhatsapp_campaigns"
        )
            ->where("status", self::STATUS_PROCESSING)
            ->where(
                "updated_at",
                "<",
                date("Y-m-d H:i:s", strtotime("-30 minutes"))
            )
            ->get();

        foreach ($processingStalledCampaigns as $campaign) {
            try {
                logActivity(
                    "Retomando campanha #{$campaign->id} interrompida há mais de 30 minutos"
                );
                $this->sendBulkMessages($campaign->id);
            } catch (\Exception $e) {
                logActivity(
                    "Erro ao retomar campanha #{$campaign->id}: " .
                        $e->getMessage()
                );
            }
        }
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
        $campaign = Capsule::table("tbladdonwhatsapp_campaigns")
            ->where("id", $campaignId)
            ->first();

        if (!$campaign) {
            logActivity("Campanha WhatsApp ID #{$campaignId} não encontrada");
            return;
        }

        // Verifica se a campanha está em um estado que permite processamento
        if (
            !in_array($campaign->status, [
                self::STATUS_READY,
                self::STATUS_PROCESSING,
            ])
        ) {
            logActivity(
                "Campanha WhatsApp ID #{$campaignId} não está pronta para processamento (status atual: {$campaign->status})"
            );
            return;
        }

        // Definir um ID único para este processamento
        $processId = uniqid("wpn_");

        // Atualiza o status para "processando"
        Capsule::table("tbladdonwhatsapp_campaigns")
            ->where("id", $campaignId)
            ->update([
                "status" => self::STATUS_PROCESSING,
                "updated_at" => date("Y-m-d H:i:s"),
            ]);

        logActivity(
            "Iniciando processamento da campanha #{$campaignId} (processo {$processId})"
        );

        // Processa mensagens em lotes para evitar timeouts
        $batchSize = 10; // Reduzido para evitar problemas de tempo limite
        $maxMessagesToProcess = 50; // Limitar o número de mensagens por execução
        $totalProcessed = 0;
        $delay = $campaign->delay;

        // Obter todas as mensagens/templates da campanha
        $templates = Capsule::table("tbladdonwhatsapp_campaign_templates")
            ->where("campaign_id", $campaignId)
            ->orderBy("id")
            ->get();

        $templateCount = count($templates);
        $templateIndex = 0;

        try {
            // Buscar mensagens pendentes em lotes pequenos
            while ($totalProcessed < $maxMessagesToProcess) {
                // Verificar se a campanha foi pausada ou cancelada durante o processamento
                $currentStatus = Capsule::table("tbladdonwhatsapp_campaigns")
                    ->where("id", $campaignId)
                    ->value("status");

                if ($currentStatus !== self::STATUS_PROCESSING) {
                    logActivity(
                        "Processamento da campanha #{$campaignId} interrompido: status alterado para {$currentStatus}"
                    );
                    break;
                }

                // Busca o próximo lote
                $messages = Capsule::table("tbladdonwhatsapp_messages")
                    ->where("campaign_id", $campaignId)
                    ->where("status", "pending")
                    ->limit($batchSize)
                    ->get();

                if (count($messages) == 0) {
                    // Não há mais mensagens para processar
                    logActivity(
                        "Não há mais mensagens pendentes na campanha #{$campaignId}"
                    );
                    break;
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
                            $template =
                                $templates[$templateIndex % $templateCount];
                            $messageText = $template->message;
                            $templateIndex++;
                        }

                        // Envia a mensagem com o template selecionado
                        $this->sendMessage(
                            $message->phone,
                            $messageText,
                            $calculatedDelay
                        );

                        // Atualiza o status da mensagem
                        Capsule::table("tbladdonwhatsapp_messages")
                            ->where("id", $message->id)
                            ->update([
                                "status" => "sent",
                                "sent_at" => date("Y-m-d H:i:s"),
                                "updated_at" => date("Y-m-d H:i:s"),
                            ]);

                        $totalProcessed++;

                        // Atualizar a contagem de mensagens enviadas da campanha
                        Capsule::table("tbladdonwhatsapp_campaigns")
                            ->where("id", $campaignId)
                            ->update([
                                "sent_count" => Capsule::raw("sent_count + 1"),
                                "updated_at" => date("Y-m-d H:i:s"),
                            ]);

                        // Dormir por um curto período para evitar sobrecarga da API
                        usleep(200000); // 200ms
                    } catch (\Exception $e) {
                        // Registra o erro
                        Capsule::table("tbladdonwhatsapp_messages")
                            ->where("id", $message->id)
                            ->update([
                                "status" => "failed",
                                "error" => $e->getMessage(),
                                "updated_at" => date("Y-m-d H:i:s"),
                            ]);

                        logActivity(
                            "Erro ao enviar mensagem #{$message->id} da campanha #{$campaignId}: " .
                                $e->getMessage()
                        );
                    }
                }

                // Verificar se deve parar
                if (count($messages) < $batchSize) {
                    break;
                }

                // Pausa entre lotes para evitar sobrecarga
                sleep(2);
            }

            // Verificar se todas as mensagens foram enviadas
            $pendingCount = Capsule::table("tbladdonwhatsapp_messages")
                ->where("campaign_id", $campaignId)
                ->where("status", "pending")
                ->count();

            if ($pendingCount == 0) {
                // Marca a campanha como concluída
                Capsule::table("tbladdonwhatsapp_campaigns")
                    ->where("id", $campaignId)
                    ->update([
                        "status" => self::STATUS_COMPLETED,
                        "updated_at" => date("Y-m-d H:i:s"),
                    ]);

                logActivity(
                    "Campanha WhatsApp ID #{$campaignId} concluída com sucesso"
                );
            } else {
                // Ainda existem mensagens a serem enviadas, manter como processando
                // para que o cron continue o trabalho na próxima execução
                logActivity(
                    "Processamento parcial da campanha #{$campaignId} concluído. {$totalProcessed} mensagens enviadas nesta execução, {$pendingCount} mensagens pendentes."
                );
            }
        } catch (\Exception $e) {
            logActivity(
                "Erro durante o processamento da campanha #{$campaignId}: " .
                    $e->getMessage()
            );

            // Manter campanha como processando para tentar novamente
            Capsule::table("tbladdonwhatsapp_campaigns")
                ->where("id", $campaignId)
                ->where("status", self::STATUS_PROCESSING)
                ->update([
                    "updated_at" => date("Y-m-d H:i:s"),
                ]);
        }
    }
}

// Instância global do notificador
$notifier = new WhatsAppNotifier();

// Registrar hooks para eventos específicos
add_hook("InvoiceCreation", 1, [$notifier, "handleInvoiceCreation"]);
add_hook("OpenpixInvoiceGenerated", 1, [
    $notifier,
    "handleOpenpixInvoiceGenerated",
]);
add_hook("InvoicePaid", 1, [$notifier, "handleInvoicePaid"]);
add_hook("InvoiceCancelled", 1, [$notifier, "handleInvoiceCancelled"]);
add_hook("InvoicePaymentReminder", 1, [
    $notifier,
    "handleInvoicePaymentReminder",
]);
add_hook("LogTransaction", 1, [$notifier, "handleLogTransaction"]);
add_hook("AffiliateWithdrawalRequest", 1, [
    $notifier,
    "handleAffiliateWithdrawalRequest",
]);
add_hook("ClientAdd", 1, [$notifier, "handleClientAdd"]);
add_hook("EmailPreSend", 1, [$notifier, "handleAuthCode"]);

// Adicionar hook para executar o processamento de campanhas após o cron
add_hook("AfterCronJob", 1, function () use ($notifier) {
    try {
        // Processar campanhas
        $notifier->processCampaigns();

        // Registrar execução bem-sucedida
        // logActivity("WhatsAppNotify: processamento de campanhas via cron executado com sucesso em " . date('Y-m-d H:i:s'));
    } catch (\Exception $e) {
        // Registrar erro no log
        logActivity(
            "Erro durante o processamento de campanhas WhatsAppNotify via cron: " .
                $e->getMessage() .
                "\n" .
                $e->getTraceAsString()
        );
    }
});
