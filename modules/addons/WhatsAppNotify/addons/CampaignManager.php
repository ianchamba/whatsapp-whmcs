<?php

if (!defined("WHMCS")) {
    die("Acesso restrito.");
}

use WHMCS\Database\Capsule;

/**
 * Retorna a lista de clientes filtrados como JSON para AJAX
 * 
 * @param array $filters Critérios de filtro
 * @return string JSON com clientes filtrados
 */
function getFilteredClientsJson($filters) {
    try {
        $clients = getFilteredClients($filters);
        
        $result = [
            'error' => false,
            'total' => count($clients),
            'clients' => []
        ];
        
        foreach ($clients as $client) {
            $result['clients'][] = [
                'id' => $client->id,
                'name' => trim($client->firstname . ' ' . $client->lastname),
                'phone' => $client->phonenumber,
            ];
        }
        
        // Garantir que não haja saída anterior que possa corromper o JSON
        if (ob_get_level()) ob_clean();
        
        // Definir cabeçalhos explicitamente
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Retornar JSON com encoding consistente
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        // Log o erro para o log do WHMCS
        logActivity('Erro ao buscar clientes filtrados: ' . $e->getMessage());
        
        // Limpar qualquer saída anterior
        if (ob_get_level()) ob_clean();
        
        // Definir cabeçalhos para erro
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        
        // Criar resposta de erro com detalhes para depuração
        $errorResponse = [
            'error' => true,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'filters' => $filters
        ];
        
        return json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

/**
 * Obtém os clientes filtrados com base nos critérios
 * 
 * @param array $filters Critérios de filtro
 * @return \Illuminate\Support\Collection Coleção de clientes
 */
function getFilteredClients($filters) {
    try {
        // Consulta base
        $query = Capsule::table('tblclients')
            ->select('id', 'firstname', 'lastname', 'email', 'phonenumber', 'companyname')
            ->where('phonenumber', '!=', '');
        
        // Aplicar filtros - Cliente ativo/inativo
        if (isset($filters['active'], $filters['inactive'])) {
            if ($filters['active'] && !$filters['inactive']) {
                $query->where('status', 'Active');
            } elseif (!$filters['active'] && $filters['inactive']) {
                $query->where('status', '!=', 'Active');
            }
        }
        
        // Filtro de produtos
        if (!empty($filters['product_id'])) {
            $query->whereIn('id', function($q) use ($filters) {
                $q->select('userid')
                  ->from('tblhosting')
                  ->where('packageid', $filters['product_id']);
            });
        }
        
        // Filtro de faturas
        if (!empty($filters['invoice_status'])) {
            $query->whereIn('id', function($q) use ($filters) {
                $q->select('userid')
                  ->from('tblinvoices');
                
                if ($filters['invoice_status'] == 'unpaid') {
                    $q->where('status', 'Unpaid');
                } elseif ($filters['invoice_status'] == 'overdue') {
                    $q->where('status', 'Unpaid')
                      ->where('duedate', '<', date('Y-m-d'));
                }
            });
        }
        
        // Executar consulta e retornar resultados
        $clients = $query->get();
        
        // Log da consulta bem-sucedida
        logActivity('Consulta de clientes filtrados: ' . count($clients) . ' encontrados. Filtros: ' . json_encode($filters));
        
        return $clients;
    } catch (Exception $e) {
        // Log do erro específico da consulta
        logActivity('Erro na consulta de clientes: ' . $e->getMessage() . '. Filtros: ' . json_encode($filters));
        
        // Re-lançar a exceção para ser tratada em níveis superiores
        throw $e;
    }
}

/**
 * Cria uma nova campanha de envio em massa com base nos dados do formulário
 * 
 * @param array $data Dados do formulário
 * @return int ID da campanha criada
 */
function createMassCampaign($data) {
    // Preparar filtros
    $filters = [
        'active' => isset($data['filter_active']) ? 1 : 0,
        'inactive' => isset($data['filter_inactive']) ? 1 : 0,
        'invoice_status' => $data['invoice_status'] ?? '',
        'product_id' => $data['product_id'] ?? '',
    ];
    
    // Determinar data agendada
    $scheduledAt = null;
    if ($data['send_time'] == 'scheduled') {
        $scheduledAt = $data['scheduled_date'] . ' ' . $data['scheduled_time'] . ':00';
    }
    
    // Inserir campanha
    $campaignId = Capsule::table('tbladdonwhatsapp_campaigns')->insertGetId([
        'name' => $data['campaign_name'],
        'message' => $data['message_template'][0] ?? '', // Mantemos para compatibilidade, mas usaremos templates separados
        'filter_criteria' => json_encode($filters),
        'scheduled_at' => $scheduledAt,
        'status' => $scheduledAt ? 'scheduled' : 'processing',
        'admin_id' => $_SESSION['adminid'],
        'delay' => (int)$data['delay'],
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ]);
    
    // Salvar as mensagens/templates
    if (isset($data['message_template']) && is_array($data['message_template'])) {
        foreach ($data['message_template'] as $template) {
            if (!empty($template)) {
                Capsule::table('tbladdonwhatsapp_campaign_templates')->insert([
                    'campaign_id' => $campaignId,
                    'message' => $template,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
    
    // Buscar os destinatários com base nos filtros
    $clients = getFilteredClients($filters);
    $messageCount = 0;
    
    // Obter todas as mensagens/templates da campanha
    $templates = Capsule::table('tbladdonwhatsapp_campaign_templates')
        ->where('campaign_id', $campaignId)
        ->orderBy('id')
        ->get();
    
    $templateCount = count($templates);
    $templateIndex = 0;
    
    // Preparar mensagens para cada cliente
    foreach ($clients as $client) {
        // Selecionar um template para este cliente (rotação de templates)
        $messageTemplate = $data['message_template'][0] ?? '';
        
        // Se houver múltiplos templates, usar um diferente para cada cliente
        if ($templateCount > 0) {
            // Selecionar o próximo template na sequência
            $template = $templates[$templateIndex % $templateCount];
            $messageTemplate = $template->message;
            $templateIndex++;
        }
        
        // Processar o template com os dados do cliente
        $message = replaceMessageVariables($messageTemplate, $client);
        
        // Salvar a mensagem
        Capsule::table('tbladdonwhatsapp_messages')->insert([
            'campaign_id' => $campaignId,
            'client_id' => $client->id,
            'phone' => $client->phonenumber,
            'message' => $message,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        $messageCount++;
    }
    
    // Atualizar campanha com contagem de destinatários
    Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('id', $campaignId)
        ->update([
            'total_recipients' => $messageCount,
        ]);
    
    // Se for envio imediato, iniciar processamento
    if (!$scheduledAt) {
        queueCampaignProcessing($campaignId);
    }
    
    return $campaignId;
}

/**
 * Substitui as variáveis da mensagem pelos dados do cliente
 * 
 * @param string $message Mensagem com variáveis
 * @param object $client Dados do cliente
 * @return string Mensagem processada
 */
function replaceMessageVariables($message, $client) {
    $replacements = [
        '{primeiro_nome}' => trim($client->firstname),
        '{sobrenome}' => trim($client->lastname),
        '{email}' => $client->email,
        '{telefone}' => $client->phonenumber,
        '{empresa}' => $client->companyname ?? '',
    ];
    
    return str_replace(array_keys($replacements), array_values($replacements), $message);
}

/**
 * Enfileira o processamento de uma campanha
 * 
 * @param int $campaignId ID da campanha
 * @return void
 */
function queueCampaignProcessing($campaignId) {
    // Inicializa o notificador WhatsApp
    $notifier = new WhatsAppNotifier();
    
    // Inicia o processamento da campanha
    $notifier->sendBulkMessages($campaignId);
}

/**
 * Cancela uma campanha
 * 
 * @param int $campaignId ID da campanha
 * @return void
 */
function cancelCampaign($campaignId) {
    Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('id', $campaignId)
        ->update([
            'status' => 'cancelled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
}

/**
 * Retoma uma campanha cancelada
 * 
 * @param int $campaignId ID da campanha
 * @return void
 */
function resumeCampaign($campaignId) {
    $campaign = Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('id', $campaignId)
        ->first();
    
    if (!$campaign) {
        return;
    }
    
    $status = 'processing';
    if ($campaign->scheduled_at && strtotime($campaign->scheduled_at) > time()) {
        $status = 'scheduled';
    }
    
    Capsule::table('tbladdonwhatsapp_campaigns')
        ->where('id', $campaignId)
        ->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    
    if ($status == 'processing') {
        queueCampaignProcessing($campaignId);
    }
}