**Módulo de Notificações no WhatsApp para WHMCS**

Este módulo permite o envio automático de notificações via WhatsApp para eventos relacionados a faturas no WHMCS, como criação, lembrete de pagamento, pagamento efetuado e cancelamento. As mensagens são personalizadas e enviadas através da Evolution API.

**Funcionalidades**

Envio Automático: Notificações automáticas para os seguintes eventos:
Criação de fatura
Fatura paga
Lembrete de pagamento
Fatura cancelada
Mensagens Personalizadas: As mensagens incluem dados como nome do cliente, valor, método de pagamento, produtos e links com AutoLogin para a área do cliente.
Integração com API: Envia mensagens diretamente usando a Evolution API.

**Instalação**
Baixe e extraia os arquivos do módulo.
Coloque o código no diretório de hooks do WHMCS (/includes/hooks).
Verifique se a Evolution API está configurada corretamente e funcional.

**Dependências**
Evolution API: Certifique-se de que a API está configurada para aceitar requisições do seu servidor WHMCS.
API Key: Substitua a chave de API no código (apikey) pela sua chave da Evolution API.

**Personalização**
O código pode ser ajustado para personalizar as mensagens ou incluir novos eventos. Os formatos de mensagens estão definidos nas funções enviarMensagemWhatsApp e enviarMensagemPix.

**Eventos Suportados**
Criação de Fatura (InvoiceCreation): Notifica o cliente sobre a criação de uma nova fatura.
Fatura Paga (InvoicePaid): Confirma o pagamento de uma fatura.
Fatura Cancelada (InvoiceCancelled): Informa o cliente sobre o cancelamento de uma fatura.
Lembrete de Pagamento (InvoicePaymentReminder): Envia um lembrete para faturas pendentes.
Fatura Pix (OpenpixInvoiceGenerated): Envia o código Pix para pagamento.

**Detalhes Técnicos**
Processamento do Número de Telefone
A função processarNumeroTelefone remove caracteres como + e . para garantir que o número esteja no formato correto para envio via API.

**Mensagens Personalizadas**
As mensagens são criadas dinamicamente com base nos dados da fatura e do cliente, incluindo:

Valor da Fatura: Formatado em moeda brasileira.
Método de Pagamento: Traduzido para nomes amigáveis (Pix, MercadoPago, etc.).
Lista de Produtos: Produtos relacionados à fatura.
Links com AutoLogin: Gerados automaticamente para acesso rápido à área do cliente.

**Requisições API**
As mensagens são enviadas via cURL para o endpoint da Evolution API configurado no módulo. O cabeçalho inclui a apikey para autenticação.

**Contribuições e Suporte**
Erros: Relate problemas ou comportamentos inesperados nos logs do WHMCS.
Novas Funcionalidades: Sugira melhorias ou novos recursos.
Logs de Debug: O módulo registra logs para auxiliar na identificação de problemas.

**Licença**
Este módulo é open source. Você pode usá-lo, modificá-lo e distribuí-lo como desejar.

---

**Contato**  
Discord: **@ianchamba**  
GitHub: [GitHub](https://github.com/ianchamba)  
OpenPix: [https://openpix.com.br/](https://openpix.com.br/)
EvolutionApi: [https://doc.evolution-api.com/v2/pt/get-started/introduction](https://doc.evolution-api.com/v2/pt/get-started/introduction)

---

