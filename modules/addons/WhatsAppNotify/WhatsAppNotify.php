<?php

if (!defined("WHMCS")) {
    die("Acesso restrito.");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/addons/CampaignManager.php';

/**
 * Configurações do módulo
 */
function WhatsAppNotify_config()
{
    return [
        'name' => 'Notificações WhatsApp',
        'description' => 'Este módulo envia notificações via WhatsApp relacionadas a faturas e eventos no WHMCS utilizando a EvolutionAPI.',
        'author' => '<a href="https://hostbraza.com.br" target="_blank" style="text-decoration:none;color:#007bff;">Hostbraza</a>',
        'language' => 'portuguese-br',
        'version' => '1.3.0',
        'fields' => [
            'apiKey' => [
                'FriendlyName' => 'API Key',
                'Type' => 'text',
                'Size' => '64',
                'Description' => 'Informe sua chave de API para envio de mensagens.',
                'Default' => 'XXXX-XXXX-XXXX-XXXX',
            ],
            'apiDomain' => [
                'FriendlyName' => 'Domínio da API',
                'Type' => 'text',
                'Size' => '64',
                'Description' => 'Informe o domínio da API (ex: api.hostbraza.com.br).',
                'Default' => 'api.hostbraza.com.br',
            ],
            'whatsAppInstance' => [
                'FriendlyName' => 'Instância do WhatsApp',
                'Type' => 'text',
                'Size' => '64',
                'Description' => 'Informe o identificador da instância (ex: Hostbraza).',
                'Default' => 'Hostbraza.com.br',
            ],
        ],
    ];
}

/**
 * Função executada ao ativar o módulo
 */
function WhatsAppNotify_activate()
{
    // Criar a tabela `tbladdonwhatsapp` caso não exista
    if (!Capsule::schema()->hasTable('tbladdonwhatsapp')) {
        Capsule::schema()->create('tbladdonwhatsapp', function ($table) {
            $table->id(); // ID automático
            $table->string('event', 100); // Nome do evento (ex: InvoiceCreation)
            $table->text('template'); // Template da mensagem
            $table->timestamps(); // Campos created_at e updated_at
            $table->charset = 'utf8mb4'; // Suporte a emojis
            $table->collation = 'utf8mb4_unicode_ci'; // Suporte a emojis
        });
    }

    // Criar tabela para campanhas de envio em massa
    if (!Capsule::schema()->hasTable('tbladdonwhatsapp_campaigns')) {
        Capsule::schema()->create('tbladdonwhatsapp_campaigns', function ($table) {
            $table->id();
            $table->string('name', 255); // Nome da campanha
            $table->text('message'); // Mensagem/template (mantido para compatibilidade)
            $table->text('filter_criteria')->nullable(); // Critérios de filtro em JSON
            $table->dateTime('scheduled_at')->nullable(); // Data/hora agendada
            $table->enum('status', ['draft', 'scheduled', 'processing', 'completed', 'cancelled'])->default('draft');
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('admin_id'); // Admin que criou a campanha
            $table->integer('delay')->default(1000); // Delay entre mensagens (ms)
            $table->timestamps();
            $table->charset = 'utf8mb4'; // Suporte a emojis
            $table->collation = 'utf8mb4_unicode_ci'; // Suporte a emojis
        });
    }

    // Criar tabela para templates de campanha
    if (!Capsule::schema()->hasTable('tbladdonwhatsapp_campaign_templates')) {
        Capsule::schema()->create('tbladdonwhatsapp_campaign_templates', function ($table) {
            $table->id();
            $table->integer('campaign_id');
            $table->text('message'); // Template da mensagem
            $table->timestamps();
            $table->charset = 'utf8mb4'; // Suporte a emojis
            $table->collation = 'utf8mb4_unicode_ci'; // Suporte a emojis
        });
    }

    // Criar tabela para mensagens
    if (!Capsule::schema()->hasTable('tbladdonwhatsapp_messages')) {
        Capsule::schema()->create('tbladdonwhatsapp_messages', function ($table) {
            $table->id();
            $table->integer('campaign_id');
            $table->integer('client_id');
            $table->string('phone', 50);
            $table->text('message');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error')->nullable(); // Mensagem de erro se aplicável
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->charset = 'utf8mb4'; // Suporte a emojis
            $table->collation = 'utf8mb4_unicode_ci'; // Suporte a emojis
        });
    }

    // Inserir templates padrão
    $defaultTemplates = [
        'InvoiceCreation' => "*Fatura em Aberto*\n\n"
            . "Olá {primeiro_nome}, tudo bem? ☺️\n\n"
            . "🔖 Você possui uma fatura em aberto #{id_fatura}.\n"
            . "💵 Valor: R$ {valor}\n"
            . "💳 Método de Pagamento: {metodo_pagamento}\n"
            . "📦 Produtos: {produtos_lista}\n\n"
            . "Para acessar sua fatura, clique no link abaixo:\n\n"
            . "{link_fatura}\n\n"
            . "_Equipe Hostbraza_",
        'InvoicePaid' => "*Fatura Paga*\n\n"
            . "Olá {primeiro_nome}, tudo bem? ☺️\n\n"
            . "✅ Sua fatura #{id_fatura} foi paga com sucesso.\n"
            . "💵 Valor: R$ {valor}\n"
            . "💳 Método de Pagamento: {metodo_pagamento}\n"
            . "📦 Produtos: {produtos_lista}\n\n"
            . "Obrigado por escolher nossos serviços! Estamos sempre à disposição. 💙\n\n"
            . "_Equipe Hostbraza_",
        'InvoiceCancelled' => "*Fatura Cancelada*\n\n"
            . "Olá {primeiro_nome}, tudo bem? ☺️\n\n"
            . "⚠️ Informamos que a fatura #{id_fatura} foi cancelada.\n"
            . "💵 Valor: R$ {valor}\n"
            . "💳 Método de Pagamento: {metodo_pagamento}\n"
            . "📦 Produtos: {produtos_lista}\n\n"
            . "Se precisar de mais informações ou ajuda, estamos à disposição. 💙\n\n"
            . "_Equipe Hostbraza_",
        'InvoicePaymentReminder' => "*Lembrete de Pagamento*\n\n"
            . "Olá {primeiro_nome}, tudo bem? ☺️\n\n"
            . "Esta mensagem é apenas um lembrete referente a fatura #{id_fatura} gerada em {data_geracao} com vencimento {data_vencimento}.\n"
            . "💵 Valor: R$ {valor}\n"
            . "💳 Método de Pagamento: {metodo_pagamento}\n"
            . "📦 Produtos: {produtos_lista}\n\n"
            . "Para acessar sua fatura, clique no link abaixo:\n\n"
            . "{link_fatura}\n\n"
            . "_Equipe Hostbraza_",
        'LateInvoicePaymentReminder' => "*Fatura em Atraso*\n\n"
            . "Olá {primeiro_nome}, tudo bem? ☺️\n\n"
            . "Gostaríamos de lembrá-lo de que a fatura #{id_fatura}, gerada em {data_geracao}, com vencimento em {data_vencimento}, ainda está pendente. Para evitar qualquer interrupção nos seus serviços, pedimos que regularize o pagamento o quanto antes.\n"
            . "💵 Valor: R$ {valor}\n"
            . "💳 Método de Pagamento: {metodo_pagamento}\n"
            . "📦 Produtos: {produtos_lista}\n\n"
            . "Para acessar sua fatura, clique no link abaixo:\n\n"
            . "{link_fatura}\n\n"
            . "_Equipe Hostbraza_",
        'InvoiceDeclined' => "*Falha no Pagamento*\n\n"
            . "Olá {primeiro_nome}, tudo bem? ☺️\n\n"
            . "❌ Notamos que a tentativa de pagamento da sua fatura #{id_fatura} foi recusada.\n"
            . "💵 Valor: R$ {valor}\n"
            . "💳 Método de Pagamento: {metodo_pagamento}\n"
            . "📦 Produtos: {produtos_lista}\n\n"
            . "Por favor, verifique seus dados ou tente um método de pagamento alternativo para evitar a interrupção dos seus serviços.\n\n"
            . "Para acessar sua fatura, clique no link abaixo:\n\n"
            . "{link_fatura}\n\n"
            . "_Equipe Hostbraza_",
        'AffiliateWithdrawalAdmin' => "*Notificação de Solicitação de Retirada de Afiliado*\n\n"
            . "🔔 Um afiliado solicitou retirada de comissão.\n"
            . "🆔 ID do Afiliado: {affId}\n"
            . "👤 Nome: {name}\n"
            . "📧 E-mail: {email}\n"
            . "👥 ID do Cliente: {clientId}\n"
            . "💰 Saldo da Conta: R$ {balance}\n\n"
            . "_Equipe Hostbraza_",
        'AffiliateWithdrawalClient' => "*Solicitação de Retirada Recebida*\n\n"
            . "Olá {name},\n\n"
            . "🔔 Recebemos sua solicitação de retirada de comissão no valor de R$ {balance}. "
            . "Nossa equipe está analisando e em breve você será notificado sobre o andamento.\n\n"
            . "_Equipe Hostbraza_",
        'ClientAdd' => "🔔 *Bem-vindo à Hostbraza, {name}!* 🔔\n\n"
            . "Obrigado por escolher a nossa hospedagem especializada em *WordPress*, *WooCommerce* e *VPS*.\n\n"
            . "📲 *Salve este número* — este é nosso canal oficial para *suporte* e *notificações de pagamento*.\n\n"
            . "🔐 *Antes de começar, verifique sua conta clicando no link abaixo:*\n{verification_link}\n\n"
            . "_Equipe Hostbraza_",
    ];

    // Inserir templates padrão
    foreach ($defaultTemplates as $event => $template) {
        if (!Capsule::table('tbladdonwhatsapp')->where('event', $event)->exists()) {
            Capsule::table('tbladdonwhatsapp')->insert([
                'event' => $event,
                'template' => $template,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    // Mensagem de sucesso
    return [
        'status' => 'success',
        'description' => 'O módulo WhatsAppNotify foi ativado com sucesso!',
    ];
}

/**
 * Função executada ao desativar o módulo
 */
function WhatsAppNotify_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'O módulo WhatsAppNotify foi desativado.',
    ];
}

/**
 * Processa campanhas agendadas no cron
 */
function WhatsAppNotify_cron()
{
    // Processar campanhas agendadas que já chegaram ao horário
    $scheduledCampaigns = Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('status', 'scheduled')
        ->where('scheduled_at', '<=', date('Y-m-d H:i:s'))
        ->get();
    
    foreach ($scheduledCampaigns as $campaign) {
        // Inicializa o notificador WhatsApp
        $notifier = new WhatsAppNotifier();
        $notifier->sendBulkMessages($campaign->id);
    }
    
    // Retomar campanhas interrompidas
    $processingStalledCampaigns = Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('status', 'processing')
        ->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-30 minutes')))
        ->get();
    
    foreach ($processingStalledCampaigns as $campaign) {
        // Inicializa o notificador WhatsApp
        $notifier = new WhatsAppNotifier();
        $notifier->sendBulkMessages($campaign->id);
    }
}

/**
 * Interface do módulo no Admin
 */
function WhatsAppNotify_output($vars)
{
    global $mysql_charset;

    // Verificar se $mysql_charset está configurado como utf8mb4
    if ($mysql_charset !== 'utf8mb4') {
        echo '<div class="alert alert-danger">';
        echo 'Atenção: A configuração <code>$mysql_charset</code> no arquivo <code>configuration.php</code> está definida como <code>' . htmlspecialchars($mysql_charset) . '</code>.';
        echo ' Para que emojis funcionem corretamente, altere para <code>utf8mb4</code>.';
        echo '</div>';
    }
    
    // Determinar a aba ativa
    $tab = $_GET['tab'] ?? 'templates';

    // Menu de navegação
    echo '<ul class="nav nav-tabs" role="tablist">';
    echo '<li role="presentation" class="' . ($tab == 'templates' ? 'active' : '') . '"><a href="?module=WhatsAppNotify&tab=templates">Templates</a></li>';
    echo '<li role="presentation" class="' . ($tab == 'mass_send' ? 'active' : '') . '"><a href="?module=WhatsAppNotify&tab=mass_send">Envio em Massa</a></li>';
    echo '<li role="presentation" class="' . ($tab == 'campaigns' ? 'active' : '') . '"><a href="?module=WhatsAppNotify&tab=campaigns">Campanhas</a></li>';
    echo '</ul>';
    echo '<div class="tab-content">';

    // Mostrar conteúdo baseado na aba
    if ($tab == 'templates') {
        outputTemplatesTab($vars);
    } elseif ($tab == 'mass_send') {
        outputMassSendTab();
    } elseif ($tab == 'campaign_details' && isset($_GET['id'])) {
        outputCampaignDetailsTab($_GET['id']);
    } elseif ($tab == 'campaigns') {
        outputCampaignsTab();
    }

    echo '</div>';
}

/**
 * Exibe a aba de templates
 */
function outputTemplatesTab($vars)
{
    // Salvar templates no banco de dados ao enviar o formulário
    if ($_POST && isset($_POST['templates'])) {
        foreach ($_POST['templates'] as $event => $template) {
            Capsule::table('tbladdonwhatsapp')
                ->updateOrInsert(
                    ['event' => $event],
                    ['template' => $template, 'updated_at' => date('Y-m-d H:i:s')]
                );
        }
        echo '<div class="alert alert-success">Templates salvos com sucesso!</div>';
    }

    // Definir templates padrão
    $defaultTemplates = [
        'InvoiceCreation' => "*Fatura em Aberto* \n\n" .
            "Olá {primeiro_nome}, tudo bem? ☺️\n\n" .
            "🔖 Você possui uma fatura em aberto #{id_fatura}.\n" .
            "💵 Valor: R$ {valor}\n" .
            "💳 Método de Pagamento: {metodo_pagamento}\n" .
            "📦 Produtos: {produtos_lista}\n\n" .
            "Para acessar sua fatura, clique no link abaixo:\n\n" .
            "{link_fatura}\n\n" .
            "_Equipe Hostbraza_",
        'InvoicePaid' => "*Fatura Paga* \n\n" .
            "Olá {primeiro_nome}, tudo bem? ☺️\n\n" .
            "✅ Sua fatura #{id_fatura} foi paga com sucesso.\n" .
            "💵 Valor: R$ {valor}\n" .
            "💳 Método de Pagamento: {metodo_pagamento}\n" .
            "📦 Produtos: {produtos_lista}\n\n" .
            "Obrigado por escolher nossos serviços! Estamos sempre à disposição. 💙\n\n" .
            "_Equipe Hostbraza_",
        'InvoiceCancelled' => "*Fatura Cancelada* \n\n" .
            "Olá {primeiro_nome}, tudo bem? ☺️\n\n" .
            "⚠️ Informamos que a fatura #{id_fatura} foi cancelada.\n" .
            "💵 Valor: R$ {valor}\n" .
            "💳 Método de Pagamento: {metodo_pagamento}\n" .
            "📦 Produtos: {produtos_lista}\n\n" .
            "Se precisar de mais informações ou ajuda, estamos à disposição. 💙\n\n" .
            "_Equipe Hostbraza_",
        'InvoicePaymentReminder' => "*Lembrete de Pagamento* \n\n" .
            "Olá {primeiro_nome}, tudo bem? ☺️\n\n" .
            "Esta mensagem é apenas um lembrete referente a fatura #{id_fatura} gerada em {data_geracao} com vencimento {data_vencimento}.\n" .
            "💵 Valor: R$ {valor}\n" .
            "💳 Método de Pagamento: {metodo_pagamento}\n" .
            "📦 Produtos: {produtos_lista}\n\n" .
            "Para acessar sua fatura, clique no link abaixo:\n\n" .
            "{link_fatura}\n\n" .
            "_Equipe Hostbraza_",
        'LateInvoicePaymentReminder' => "*Fatura em Atraso* \n\n" .
            "Olá {primeiro_nome}, tudo bem? ☺️\n\n" .
            "Gostaríamos de lembrá-lo de que a fatura #{id_fatura}, gerada em {data_geracao}, com vencimento em {data_vencimento}, ainda está pendente. Para evitar qualquer interrupção nos seus serviços, pedimos que regularize o pagamento o quanto antes.\n" .
            "💵 Valor: R$ {valor}\n" .
            "💳 Método de Pagamento: {metodo_pagamento}\n" .
            "📦 Produtos: {produtos_lista}\n\n" .
            "Para acessar sua fatura, clique no link abaixo:\n\n" .
            "{link_fatura}\n\n" .
            "_Equipe Hostbraza_",
        'InvoiceDeclined' => "*Falha no Pagamento* \n\n" .
            "Olá {primeiro_nome}, tudo bem? ☺️\n\n" .
            "❌ Notamos que a tentativa de pagamento da sua fatura #{id_fatura}, foi recusada.\n" .
            "💵 Valor: R$ {valor}\n" .
            "💳 Método de Pagamento: {metodo_pagamento}\n" .
            "📦 Produtos: {produtos_lista}\n\n" .
            "Por favor, verifique os seus dados ou tente um método de pagamento alternativo para evitar a interrupção dos seus serviços. \n\n" .
            "Para acessar sua fatura, clique no link abaixo:\n\n" .
            "{link_fatura}\n\n" .
            "_Equipe Hostbraza_",
        'AffiliateWithdrawalAdmin' => "*Notificação de Solicitação de Retirada de Afiliado*\n\n" .
            "🔔 Um afiliado solicitou retirada de comissão.\n" .
            "🆔 ID do Afiliado: {affId}\n" .
            "👤 Nome: {name}\n" .
            "📧 E-mail: {email}\n" .
            "👥 ID do Cliente: {clientId}\n" .
            "💰 Saldo da Conta: R$ {balance}\n\n" .
            "_Equipe Hostbraza_",
        'AffiliateWithdrawalClient' => "*Solicitação de Retirada Recebida*\n\n" .
            "Olá {name},\n\n" .
            "🔔 Recebemos sua solicitação de retirada de comissão no valor de R$ {balance}. " .
            "Nossa equipe está analisando e em breve você será notificado sobre o andamento.\n\n" .
            "_Equipe Hostbraza_",
        'ClientAdd' => "🔔 *Bem-vindo à Hostbraza, {name}!* 🔔\n\n" .
            "Obrigado por escolher a nossa hospedagem especializada em *WordPress*, *WooCommerce* e *VPS*.\n\n" .
            "📲 *Salve este número* — este é nosso canal oficial para *suporte* e *notificações de pagamento*.\n\n" .
            "🔐 *Antes de começar, verifique sua conta clicando no link abaixo:*\n{verification_link}\n\n" .
            "_Equipe Hostbraza_",
    ];

    // Obter templates salvos
    $events = [
        'InvoiceCreation', 'InvoicePaid', 'InvoiceCancelled', 
        'InvoicePaymentReminder', 'LateInvoicePaymentReminder', 'InvoiceDeclined',
        'AffiliateWithdrawalAdmin', 'AffiliateWithdrawalClient', 'ClientAdd'
    ];
    
    $templates = [];
    foreach ($events as $event) {
        $templates[$event] = Capsule::table('tbladdonwhatsapp')
            ->where('event', $event)
            ->value('template') ?? $defaultTemplates[$event] ?? '';
    }

    // Formulário para editar templates
    echo '<div class="tab-pane active">';
    echo '<form method="post">';
    echo '<h2>Configuração de Templates de Mensagens</h2>';
    echo '<p>Utilize as tags disponíveis para cada tipo de template:</p>';
    
    echo '<div class="panel-group" id="accordion" role="tablist" aria-multiselectable="true">';
    
    // Informações sobre tags disponíveis
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading" role="tab" id="headingTags">';
    echo '<h4 class="panel-title">';
    echo '<a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseTags" aria-expanded="false" aria-controls="collapseTags">';
    echo 'Variáveis disponíveis para templates';
    echo '</a>';
    echo '</h4>';
    echo '</div>';
    echo '<div id="collapseTags" class="panel-collapse collapse" role="tabpanel" aria-labelledby="headingTags">';
    echo '<div class="panel-body">';
    echo '<h5>Templates de Faturas:</h5>';
    echo '<ul>';
    echo '<li><code>{primeiro_nome}</code> - Nome do cliente</li>';
    echo '<li><code>{id_fatura}</code> - ID da fatura</li>';
    echo '<li><code>{metodo_pagamento}</code> - Método de pagamento</li>';
    echo '<li><code>{valor}</code> - Valor da fatura</li>';
    echo '<li><code>{data_geracao}</code> - Data de geração da fatura</li>';
    echo '<li><code>{data_vencimento}</code> - Data de vencimento da fatura</li>';
    echo '<li><code>{produtos_lista}</code> - Lista de produtos</li>';
    echo '<li><code>{link_fatura}</code> - Link da fatura</li>';
    echo '</ul>';
    
    echo '<h5>Templates de Afiliados:</h5>';
    echo '<ul>';
    echo '<li><code>{affId}</code> - ID do afiliado</li>';
    echo '<li><code>{name}</code> - Nome do afiliado</li>';
    echo '<li><code>{email}</code> - Email do afiliado</li>';
    echo '<li><code>{clientId}</code> - ID do cliente</li>';
    echo '<li><code>{balance}</code> - Saldo disponível</li>';
    echo '</ul>';
    
    echo '<h5>Template de Boas-vindas:</h5>';
    echo '<ul>';
    echo '<li><code>{name}</code> - Nome do cliente</li>';
    echo '<li><code>{clientId}</code> - ID do cliente</li>';
    echo '<li><code>{verification_link}</code> - Link de verificação da conta</li>';
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Templates de fatura
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading" role="tab" id="headingInvoices">';
    echo '<h4 class="panel-title">';
    echo '<a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseInvoices" aria-expanded="true" aria-controls="collapseInvoices">';
    echo 'Templates de Fatura';
    echo '</a>';
    echo '</h4>';
    echo '</div>';
    echo '<div id="collapseInvoices" class="panel-collapse collapse in" role="tabpanel" aria-labelledby="headingInvoices">';
    echo '<div class="panel-body">';
    
    $invoiceEvents = ['InvoiceCreation', 'InvoicePaid', 'InvoiceCancelled', 'InvoicePaymentReminder', 'LateInvoicePaymentReminder', 'InvoiceDeclined'];
    foreach ($invoiceEvents as $event) {
        echo '<div class="form-group">';
        echo '<label for="template_' . $event . '">' . ucfirst($event) . '</label>';
        echo '<textarea id="template_' . $event . '" name="templates[' . $event . ']" class="form-control" rows="5">' . htmlspecialchars($templates[$event]) . '</textarea>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Templates de afiliados
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading" role="tab" id="headingAffiliates">';
    echo '<h4 class="panel-title">';
    echo '<a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseAffiliates" aria-expanded="false" aria-controls="collapseAffiliates">';
    echo 'Templates de Afiliados';
    echo '</a>';
    echo '</h4>';
    echo '</div>';
    echo '<div id="collapseAffiliates" class="panel-collapse collapse" role="tabpanel" aria-labelledby="headingAffiliates">';
    echo '<div class="panel-body">';
    
    $affiliateEvents = ['AffiliateWithdrawalAdmin', 'AffiliateWithdrawalClient'];
    foreach ($affiliateEvents as $event) {
        echo '<div class="form-group">';
        echo '<label for="template_' . $event . '">' . ucfirst($event) . '</label>';
        echo '<textarea id="template_' . $event . '" name="templates[' . $event . ']" class="form-control" rows="5">' . htmlspecialchars($templates[$event]) . '</textarea>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    // Outros templates
    echo '<div class="panel panel-default">';
    echo '<div class="panel-heading" role="tab" id="headingOthers">';
    echo '<h4 class="panel-title">';
    echo '<a role="button" data-toggle="collapse" data-parent="#accordion" href="#collapseOthers" aria-expanded="false" aria-controls="collapseOthers">';
    echo 'Outros Templates';
    echo '</a>';
    echo '</h4>';
    echo '</div>';
    echo '<div id="collapseOthers" class="panel-collapse collapse" role="tabpanel" aria-labelledby="headingOthers">';
    echo '<div class="panel-body">';
    
    $otherEvents = ['ClientAdd'];
    foreach ($otherEvents as $event) {
        echo '<div class="form-group">';
        echo '<label for="template_' . $event . '">' . ucfirst($event) . '</label>';
        echo '<textarea id="template_' . $event . '" name="templates[' . $event . ']" class="form-control" rows="5">' . htmlspecialchars($templates[$event]) . '</textarea>';
        echo '</div>';
    }
    
    echo '</div>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>'; // Fim do accordion
    
    echo '<button type="submit" class="btn btn-primary">Salvar Templates</button>';
    echo '</form>';
    echo '</div>';
}

/**
 * Exibe a aba de envio em massa
 */
function outputMassSendTab() {
    // Processar formulário de envio
    if (isset($_POST['action']) && $_POST['action'] == 'create_campaign') {
        $campaignId = createMassCampaign($_POST);
        echo '<div class="alert alert-success">Campanha criada com sucesso! <a href="?module=WhatsAppNotify&tab=campaign_details&id=' . $campaignId . '">Ver detalhes</a></div>';
    }
    
    // Processar requisição AJAX para buscar clientes filtrados
    if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_filtered_clients') {
        header('Content-Type: application/json');
        
        try {
            $filters = [
                'active' => isset($_GET['filter_active']) && $_GET['filter_active'] === 'true' ? 1 : 0,
                'inactive' => isset($_GET['filter_inactive']) && $_GET['filter_inactive'] === 'true' ? 1 : 0,
                'invoice_status' => $_GET['invoice_status'] ?? '',
                'product_id' => $_GET['product_id'] ?? '',
            ];
            
            echo getFilteredClientsJson($filters);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Erro ao processar requisição: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'filters' => $filters ?? 'Filtros não definidos'
            ]);
        }
        
        exit;
    }
    
    ?>
    <div class="tab-pane active">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Nova Campanha de Envio em Massa</h3>
            </div>
            <div class="panel-body">
                <form method="post" action="" id="campaignForm">
                    <input type="hidden" name="action" value="create_campaign">
                    
                    <div class="form-group">
                        <label for="campaign_name">Nome da Campanha</label>
                        <input type="text" class="form-control" id="campaign_name" name="campaign_name" required>
                    </div>
                    
                    <!-- Seleção de destinatários -->
                    <div class="form-group">
                        <label>Filtrar Clientes</label>
                        <div class="row">
                            <div class="col-md-4">
                                <label><input type="checkbox" name="filter_active" id="filter_active" value="1" checked> Clientes Ativos</label>
                            </div>
                            <div class="col-md-4">
                                <label><input type="checkbox" name="filter_inactive" id="filter_inactive" value="1"> Clientes Inativos</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Filtros Adicionais</label>
                        <div class="row">
                            <div class="col-md-4">
                                <select class="form-control" name="invoice_status" id="invoice_status">
                                    <option value="">-- Status de Fatura --</option>
                                    <option value="unpaid">Faturas em Aberto</option>
                                    <option value="overdue">Faturas em Atraso</option>
                                    <option value="any">Qualquer Status</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-control" name="product_id" id="product_id">
                                    <option value="">-- Serviço Contratado --</option>
                                    <?php
                                    $products = Capsule::table('tblproducts')
                                        ->select('id', 'name')
                                        ->orderBy('name')
                                        ->get();
                                    foreach ($products as $product) {
                                        echo '<option value="' . $product->id . '">' . htmlspecialchars($product->name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Lista de destinatários -->
                    <div class="form-group">
                        <label>Destinatários</label>
                        <div class="panel panel-default">
                            <div class="panel-heading">
                                <span id="recipient-count">Carregando...</span>
                                <span id="loading-indicator" class="pull-right"><i class="fa fa-spinner fa-spin"></i> Carregando...</span>
                            </div>
                            <div class="panel-body" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-striped table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Telefone</th>
                                        </tr>
                                    </thead>
                                    <tbody id="recipients-list">
                                        <tr>
                                            <td colspan="2" class="text-center">Carregando destinatários...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div id="error-container" class="alert alert-danger" style="display: none;"></div>
                    </div>
                    
                    <!-- Composição das mensagens -->
                    <div class="form-group">
                        <label>Mensagens (Serão rotacionadas para aumentar a variedade)</label>
                        <div id="messages-container">
                            <div class="message-template">
                                <textarea name="message_template[]" class="form-control" rows="6" required placeholder="Digite a mensagem aqui... Variáveis disponíveis: {primeiro_nome}, {sobrenome}, {email}, {telefone}, {empresa}"></textarea>
                                <div class="text-right">
                                    <button type="button" class="btn btn-sm btn-danger remove-message" style="margin-top: 5px; display: none;">Remover</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-info" id="add-message" style="margin-top: 10px;">+ Adicionar Mais Mensagens</button>
                        <p class="help-block">
                            Variáveis disponíveis: {primeiro_nome}, {sobrenome}, {email}, {telefone}, {empresa}
                        </p>
                    </div>
                    
                    <!-- Opções de envio -->
                    <div class="form-group">
                        <label>Opções de Envio</label>
                        <div class="row">
                            <div class="col-md-4">
                                <label><input type="radio" name="send_time" value="now" checked> Enviar Agora</label>
                            </div>
                            <div class="col-md-4">
                                <label><input type="radio" name="send_time" value="scheduled"> Agendar</label>
                            </div>
                        </div>
                    </div>
                    
                    <div id="scheduled_options" style="display:none;">
                        <div class="form-group">
                            <label>Data e Hora</label>
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="date" class="form-control" name="scheduled_date">
                                </div>
                                <div class="col-md-4">
                                    <input type="time" class="form-control" name="scheduled_time">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="delay">Intervalo entre mensagens (ms)</label>
                        <input type="number" class="form-control" id="delay" name="delay" value="1000" min="500">
                        <p class="help-block">Intervalo recomendado: 1000ms (1 segundo)</p>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Criar Campanha</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function() {
        // Toggle de agendamento
        jQuery('input[name="send_time"]').change(function() {
            if (jQuery(this).val() == 'scheduled') {
                jQuery('#scheduled_options').show();
            } else {
                jQuery('#scheduled_options').hide();
            }
        });
        
        // Adicionar mais templates de mensagem
        jQuery('#add-message').click(function() {
            var messageTemplate = jQuery('.message-template').first().clone();
            messageTemplate.find('textarea').val('');
            messageTemplate.find('.remove-message').show();
            jQuery('#messages-container').append(messageTemplate);
            updateRemoveButtons();
        });
        
        // Remover template de mensagem
        jQuery(document).on('click', '.remove-message', function() {
            jQuery(this).closest('.message-template').remove();
            updateRemoveButtons();
        });
        
        function updateRemoveButtons() {
            // Esconder botão de remover se houver apenas um template
            if (jQuery('.message-template').length === 1) {
                jQuery('.remove-message').hide();
            } else {
                jQuery('.remove-message').show();
            }
        }
        
        // Carregar destinatários iniciais
        loadFilteredClients();
        
        // Atualizar lista de destinatários quando os filtros forem alterados
        jQuery('#filter_active, #filter_inactive, #invoice_status, #product_id').change(function() {
            loadFilteredClients();
        });
        
        function loadFilteredClients() {
            // Mostrar indicador de carregamento
            jQuery('#loading-indicator').show();
            jQuery('#error-container').hide();
            
            // Preparar os filtros
            var filters = {
                filter_active: jQuery('#filter_active').is(':checked'),
                filter_inactive: jQuery('#filter_inactive').is(':checked'),
                invoice_status: jQuery('#invoice_status').val(),
                product_id: jQuery('#product_id').val()
            };
            
            // Atualizar UI
            jQuery('#recipient-count').text('Carregando...');
            jQuery('#recipients-list').html('<tr><td colspan="2" class="text-center">Carregando destinatários...</td></tr>');
            
            // Fazer a requisição AJAX
            jQuery.ajax({
                url: window.location.href,
                type: 'GET',
                data: {
                    module: 'WhatsAppNotify',
                    tab: 'mass_send',
                    ajax: 'get_filtered_clients',
                    filter_active: filters.filter_active,
                    filter_inactive: filters.filter_inactive,
                    invoice_status: filters.invoice_status,
                    product_id: filters.product_id
                },
                dataType: 'json',
                success: function(response) {
                    // Esconder indicador de carregamento
                    jQuery('#loading-indicator').hide();
                    
                    // Verificar se há erro na resposta
                    if (response.error) {
                        jQuery('#error-container').html('<strong>Erro ao carregar destinatários:</strong> ' + response.message).show();
                        jQuery('#recipient-count').text('Erro ao carregar destinatários');
                        jQuery('#recipients-list').html('<tr><td colspan="2" class="text-center">Ocorreu um erro ao carregar destinatários</td></tr>');
                        return;
                    }
                    
                    // Atualizar contador
                    jQuery('#recipient-count').text(response.total + ' destinatários encontrados');
                    
                    // Atualizar lista de destinatários
                    var html = '';
                    if (response.clients.length > 0) {
                        jQuery.each(response.clients, function(index, client) {
                            html += '<tr>';
                            html += '<td>' + client.name + '</td>';
                            html += '<td>' + client.phone + '</td>';
                            html += '</tr>';
                        });
                    } else {
                        html = '<tr><td colspan="2" class="text-center">Nenhum destinatário encontrado</td></tr>';
                    }
                    
                    jQuery('#recipients-list').html(html);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // Esconder indicador de carregamento
                    jQuery('#loading-indicator').hide();
                    
                    // Mostrar erro detalhado
                    var errorDetails = '';
                    try {
                        var error = JSON.parse(jqXHR.responseText);
                        errorDetails = '<strong>Detalhes técnicos:</strong><br>';
                        errorDetails += 'Mensagem: ' + (error.message || 'Desconhecido') + '<br>';
                        errorDetails += 'Filtros: ' + JSON.stringify(filters) + '<br>';
                        
                        if (error.trace) {
                            errorDetails += '<details><summary>Stack Trace (clique para expandir)</summary>' + 
                                '<pre style="max-height: 200px; overflow-y: auto;">' + error.trace + '</pre></details>';
                        }
                    } catch (e) {
                        errorDetails = 'Status: ' + jqXHR.status + ' ' + textStatus + '<br>' +
                                      'Resposta do Servidor: ' + jqXHR.responseText;
                    }
                    
                    jQuery('#error-container').html('<strong>Erro ao carregar destinatários</strong><br>' + errorDetails).show();
                    jQuery('#recipient-count').text('Erro ao carregar destinatários');
                    jQuery('#recipients-list').html('<tr><td colspan="2" class="text-center">Ocorreu um erro ao carregar destinatários. Veja os detalhes acima.</td></tr>');
                }
            });
        }
    });
    </script>
    <?php
}

/**
 * Exibe a aba de campanhas
 */
function outputCampaignsTab() {
    // Processar ações
    if (isset($_GET['action'])) {
        if ($_GET['action'] == 'cancel' && isset($_GET['id'])) {
            cancelCampaign($_GET['id']);
            echo '<div class="alert alert-success">Campanha cancelada com sucesso.</div>';
        } elseif ($_GET['action'] == 'resume' && isset($_GET['id'])) {
            resumeCampaign($_GET['id']);
            echo '<div class="alert alert-success">Campanha retomada com sucesso.</div>';
        }
    }
    
    ?>
    <div class="tab-pane active">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Campanhas de Envio em Massa</h3>
            </div>
            <div class="panel-body">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Status</th>
                            <th>Progresso</th>
                            <th>Agendado para</th>
                            <th>Criado em</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $campaigns = Capsule::table('tbladdonwhatsapp_campaigns')
                            ->orderBy('created_at', 'desc')
                            ->get();
                        
                        foreach ($campaigns as $campaign) {
                            $progress = $campaign->total_recipients > 0 
                                ? round(($campaign->sent_count / $campaign->total_recipients) * 100, 1) 
                                : 0;
                            
                            $statusClass = '';
                            switch ($campaign->status) {
                                case 'draft': $statusClass = 'default'; break;
                                case 'scheduled': $statusClass = 'info'; break;
                                case 'processing': $statusClass = 'warning'; break;
                                case 'completed': $statusClass = 'success'; break;
                                case 'cancelled': $statusClass = 'danger'; break;
                            }
                            
                            echo '<tr>';
                            echo '<td>' . $campaign->id . '</td>';
                            echo '<td>' . htmlspecialchars($campaign->name) . '</td>';
                            echo '<td><span class="label label-' . $statusClass . '">' . ucfirst($campaign->status) . '</span></td>';
                            echo '<td>';
                            echo '<div class="progress">';
                            echo '<div class="progress-bar" role="progressbar" style="width: ' . $progress . '%;">';
                            echo $campaign->sent_count . '/' . $campaign->total_recipients . ' (' . $progress . '%)';
                            echo '</div>';
                            echo '</div>';
                            echo '</td>';
                            echo '<td>' . ($campaign->scheduled_at ? date('d/m/Y H:i', strtotime($campaign->scheduled_at)) : 'Imediato') . '</td>';
                            echo '<td>' . date('d/m/Y H:i', strtotime($campaign->created_at)) . '</td>';
                            echo '<td>';
                            
                            if ($campaign->status == 'scheduled' || $campaign->status == 'draft') {
                                echo '<a href="?module=WhatsAppNotify&tab=campaigns&action=cancel&id=' . $campaign->id . '" class="btn btn-sm btn-danger">Cancelar</a> ';
                            } elseif ($campaign->status == 'cancelled' && $campaign->sent_count < $campaign->total_recipients) {
                                echo '<a href="?module=WhatsAppNotify&tab=campaigns&action=resume&id=' . $campaign->id . '" class="btn btn-sm btn-success">Retomar</a> ';
                            }
                            
                            echo '<a href="?module=WhatsAppNotify&tab=campaign_details&id=' . $campaign->id . '" class="btn btn-sm btn-info">Detalhes</a>';
                            echo '</td>';
                            echo '</tr>';
                        }
                        
                        if (count($campaigns) == 0) {
                            echo '<tr><td colspan="7" class="text-center">Nenhuma campanha encontrada</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

/**
 * Exibe a aba de detalhes da campanha
 * 
 * @param int $campaignId ID da campanha
 */
function outputCampaignDetailsTab($campaignId) {
    $campaign = Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('id', $campaignId)
        ->first();
    
    if (!$campaign) {
        echo '<div class="alert alert-danger">Campanha não encontrada.</div>';
        return;
    }
    
    // Obter estatísticas
    $stats = [
        'pending' => Capsule::table('tbladdonwhatsapp_messages')
            ->where('campaign_id', $campaignId)
            ->where('status', 'pending')
            ->count(),
        'sent' => Capsule::table('tbladdonwhatsapp_messages')
            ->where('campaign_id', $campaignId)
            ->where('status', 'sent')
            ->count(),
        'failed' => Capsule::table('tbladdonwhatsapp_messages')
            ->where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->count(),
    ];
    
    // Obter filtros
    $filters = json_decode($campaign->filter_criteria, true);
    
    // Obter templates
    $templates = Capsule::table('tbladdonwhatsapp_campaign_templates')
        ->where('campaign_id', $campaignId)
        ->get();
    
    ?>
    <div class="tab-pane active">
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title">Detalhes da Campanha: <?php echo htmlspecialchars($campaign->name); ?></h3>
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4>Informações Gerais</h4>
                        <table class="table table-striped">
                            <tr>
                                <th>ID</th>
                                <td><?php echo $campaign->id; ?></td>
                            </tr>
                            <tr>
                                <th>Nome</th>
                                <td><?php echo htmlspecialchars($campaign->name); ?></td>
                            </tr>
                            <tr>
                                <th>Status</th>
                                <td>
                                    <?php 
                                    $statusClass = '';
                                    switch ($campaign->status) {
                                        case 'draft': $statusClass = 'default'; break;
                                        case 'scheduled': $statusClass = 'info'; break;
                                        case 'processing': $statusClass = 'warning'; break;
                                        case 'completed': $statusClass = 'success'; break;
                                        case 'cancelled': $statusClass = 'danger'; break;
                                    }
                                    echo '<span class="label label-' . $statusClass . '">' . ucfirst($campaign->status) . '</span>';
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Agendada para</th>
                                <td><?php echo $campaign->scheduled_at ? date('d/m/Y H:i:s', strtotime($campaign->scheduled_at)) : 'Envio imediato'; ?></td>
                            </tr>
                            <tr>
                                <th>Criada em</th>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($campaign->created_at)); ?></td>
                            </tr>
                            <tr>
                                <th>Atualizada em</th>
                                <td><?php echo date('d/m/Y H:i:s', strtotime($campaign->updated_at)); ?></td>
                            </tr>
                            <tr>
                                <th>Delay entre mensagens</th>
                                <td><?php echo $campaign->delay; ?>ms</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4>Estatísticas</h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="panel panel-primary">
                                    <div class="panel-heading text-center">
                                        <h3 class="panel-title">Total</h3>
                                    </div>
                                    <div class="panel-body text-center">
                                        <h3><?php echo $campaign->total_recipients; ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="panel panel-success">
                                    <div class="panel-heading text-center">
                                        <h3 class="panel-title">Enviados</h3>
                                    </div>
                                    <div class="panel-body text-center">
                                        <h3><?php echo $stats['sent']; ?></h3>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="panel panel-danger">
                                    <div class="panel-heading text-center">
                                        <h3 class="panel-title">Falhas</h3>
                                    </div>
                                    <div class="panel-body text-center">
                                        <h3><?php echo $stats['failed']; ?></h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="progress">
                            <?php
                            $progress = $campaign->total_recipients > 0 
                                ? round(($campaign->sent_count / $campaign->total_recipients) * 100, 1) 
                                : 0;
                            ?>
                            <div class="progress-bar progress-bar-success" role="progressbar" style="width: <?php echo $progress; ?>%;">
                                <?php echo $progress; ?>%
                            </div>
                        </div>
                        
                        <h4>Filtros Aplicados</h4>
                        <ul>
                            <?php
                            if (isset($filters['active']) && $filters['active']) {
                                echo '<li>Clientes ativos</li>';
                            }
                            if (isset($filters['inactive']) && $filters['inactive']) {
                                echo '<li>Clientes inativos</li>';
                            }
                            if (isset($filters['invoice_status']) && $filters['invoice_status']) {
                                switch ($filters['invoice_status']) {
                                    case 'unpaid':
                                        echo '<li>Clientes com faturas em aberto</li>';
                                        break;
                                    case 'overdue':
                                        echo '<li>Clientes com faturas em atraso</li>';
                                        break;
                                    case 'any':
                                        echo '<li>Clientes com qualquer fatura</li>';
                                        break;
                                }
                            }
                            if (isset($filters['product_id']) && $filters['product_id']) {
                                $productName = Capsule::table('tblproducts')
                                    ->where('id', $filters['product_id'])
                                    ->value('name');
                                echo '<li>Clientes com o produto: ' . htmlspecialchars($productName) . '</li>';
                            }
                            ?>
                        </ul>
                    </div>
                </div>
                
                <h4>Mensagens (<?php echo count($templates); ?> templates)</h4>
                <?php if (count($templates) > 0): ?>
                    <div class="panel-group" id="accordion-templates" role="tablist" aria-multiselectable="true">
                        <?php foreach ($templates as $index => $template): ?>
                            <div class="panel panel-default">
                                <div class="panel-heading" role="tab" id="heading-template-<?php echo $index; ?>">
                                    <h4 class="panel-title">
                                        <a role="button" data-toggle="collapse" data-parent="#accordion-templates" href="#collapse-template-<?php echo $index; ?>" aria-expanded="<?php echo $index === 0 ? 'true' : 'false'; ?>" aria-controls="collapse-template-<?php echo $index; ?>">
                                            Template #<?php echo $index + 1; ?>
                                        </a>
                                    </h4>
                                </div>
                                <div id="collapse-template-<?php echo $index; ?>" class="panel-collapse collapse <?php echo $index === 0 ? 'in' : ''; ?>" role="tabpanel" aria-labelledby="heading-template-<?php echo $index; ?>">
                                    <div class="panel-body">
                                        <div class="well" style="white-space: pre-wrap;"><?php echo htmlspecialchars($template->message); ?></div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="well" style="white-space: pre-wrap;"><?php echo htmlspecialchars($campaign->message); ?></div>
                <?php endif; ?>
                
                <h4>Destinatários</h4>
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Telefone</th>
                            <th>Status</th>
                            <th>Enviado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
                        $perPage = 20;
                        $offset = ($page - 1) * $perPage;
                        
                        $messages = Capsule::table('tbladdonwhatsapp_messages AS m')
                            ->select('m.*', 'c.firstname', 'c.lastname')
                            ->join('tblclients AS c', 'c.id', '=', 'm.client_id')
                            ->where('m.campaign_id', $campaignId)
                            ->orderBy('m.id')
                            ->offset($offset)
                            ->limit($perPage)
                            ->get();
                        
                        foreach ($messages as $message) {
                            $statusClass = '';
                            switch ($message->status) {
                                case 'pending': $statusClass = 'default'; break;
                                case 'sent': $statusClass = 'success'; break;
                                case 'failed': $statusClass = 'danger'; break;
                            }
                            
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($message->firstname . ' ' . $message->lastname) . '</td>';
                            echo '<td>' . htmlspecialchars($message->phone) . '</td>';
                            echo '<td><span class="label label-' . $statusClass . '">' . ucfirst($message->status) . '</span></td>';
                            echo '<td>' . ($message->sent_at ? date('d/m/Y H:i:s', strtotime($message->sent_at)) : '-') . '</td>';
                            echo '</tr>';
                        }
                        
                        if (count($messages) == 0) {
                            echo '<tr><td colspan="4" class="text-center">Nenhum destinatário encontrado</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
                
                <?php
                // Links de paginação
                $totalCount = Capsule::table('tbladdonwhatsapp_messages')
                    ->where('campaign_id', $campaignId)
                    ->count();
                    
                $totalPages = ceil($totalCount / $perPage);
                
                if ($totalPages > 1) {
                    echo '<div class="text-center"><ul class="pagination">';
                    
                    // Botão anterior
                    if ($page > 1) {
                        echo '<li><a href="?module=WhatsAppNotify&tab=campaign_details&id=' . $campaignId . '&page=' . ($page - 1) . '">&laquo;</a></li>';
                    } else {
                        echo '<li class="disabled"><a href="#">&laquo;</a></li>';
                    }
                    
                    // Páginas
                    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
                        if ($i == $page) {
                            echo '<li class="active"><a href="#">' . $i . '</a></li>';
                        } else {
                            echo '<li><a href="?module=WhatsAppNotify&tab=campaign_details&id=' . $campaignId . '&page=' . $i . '">' . $i . '</a></li>';
                        }
                    }
                    
                    // Botão próximo
                    if ($page < $totalPages) {
                        echo '<li><a href="?module=WhatsAppNotify&tab=campaign_details&id=' . $campaignId . '&page=' . ($page + 1) . '">&raquo;</a></li>';
                    } else {
                        echo '<li class="disabled"><a href="#">&raquo;</a></li>';
                    }
                    
                    echo '</ul></div>';
                }
                ?>
                
                <div class="text-center">
                    <a href="?module=WhatsAppNotify&tab=campaigns" class="btn btn-default">Voltar para Campanhas</a>
                    
                    <?php
                    if ($campaign->status == 'scheduled' || $campaign->status == 'draft') {
                        echo '<a href="?module=WhatsAppNotify&tab=campaigns&action=cancel&id=' . $campaignId . '" class="btn btn-danger" onclick="return confirm(\'Tem certeza que deseja cancelar esta campanha?\')">Cancelar Campanha</a>';
                    } elseif ($campaign->status == 'cancelled' && $campaign->sent_count < $campaign->total_recipients) {
                        echo '<a href="?module=WhatsAppNotify&tab=campaigns&action=resume&id=' . $campaignId . '" class="btn btn-success">Retomar Campanha</a>';
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}