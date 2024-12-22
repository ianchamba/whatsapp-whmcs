<?php

if (!defined("WHMCS")) {
    die("Acesso restrito.");
}

use WHMCS\Database\Capsule;

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
        'version' => '1.1.1',
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
    
    // Salvar templates no banco de dados ao enviar o formulário
    if ($_POST) {
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
    ];

    // Obter templates salvos
    $events = ['InvoiceCreation', 'InvoicePaid', 'InvoiceCancelled', 'InvoicePaymentReminder'];
    $templates = [];
    foreach ($events as $event) {
        $templates[$event] = Capsule::table('tbladdonwhatsapp')
            ->where('event', $event)
            ->value('template') ?? $defaultTemplates[$event];
    }

    // Formulário para editar templates
    echo '<form method="post">';
    echo '<h2>Configuração de Templates de Mensagens</h2>';
    echo '<p>Utilize as tags: <code>{primeiro_nome}</code>, <code>{id_fatura}</code>, <code>{metodo_pagamento}</code>, <code>{data_geracao}</code>, <code>{data_vencimento}</code>, <code>{valor}</code>, <code>{produtos_lista}</code>, <code>{link_fatura}</code></p>';
    foreach ($templates as $event => $template) {
        echo '<div class="form-group">';
        echo '<label for="template_' . $event . '">' . ucfirst($event) . '</label>';
        echo '<textarea id="template_' . $event . '" name="templates[' . $event . ']" class="form-control" rows="5">' . htmlspecialchars($template) . '</textarea>';
        echo '</div>';
    }
    echo '<button type="submit" class="btn btn-primary">Salvar Templates</button>';
    echo '</form>';
}