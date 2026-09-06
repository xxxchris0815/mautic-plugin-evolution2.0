<?php

/**
 * Classe de Integração do Plugin MauticEvolution
 * 
 * Este arquivo contém a lógica principal de integração do plugin com o sistema Mautic.
 * Uma integração é responsável por definir como o plugin se conecta com serviços
 * externos, processa dados e interage com o sistema de contatos do Mautic.
 */

namespace MauticPlugin\MauticEvolutionBundle\Integration;

// Importação de todas as classes necessárias para a integração
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;             // Tipo de campo sim/não
use Symfony\Component\Form\FormBuilder;                            // Para builder tipado
use Doctrine\ORM\EntityManager;                           // Para operações no banco de dados
use Mautic\CoreBundle\Helper\CacheStorageHelper;          // Para gerenciamento de cache
use Mautic\CoreBundle\Helper\EncryptionHelper;            // Para criptografia de dados sensíveis
use Mautic\CoreBundle\Helper\PathsHelper;                 // Para trabalhar com caminhos de arquivos
use Mautic\CoreBundle\Model\NotificationModel;            // Para envio de notificações
use Mautic\LeadBundle\Field\FieldsWithUniqueIdentifier;   // Para campos únicos de contatos
use Mautic\LeadBundle\Model\CompanyModel;                 // Para trabalhar com empresas
use Mautic\LeadBundle\Model\DoNotContact as DoNotContactModel; // Para lista de não contatar
use Mautic\LeadBundle\Model\FieldModel;                   // Para campos customizados
use Mautic\LeadBundle\Model\LeadModel;                    // Para trabalhar com contatos
use Mautic\PluginBundle\Integration\AbstractIntegration;  // Classe base para integrações
use Mautic\PluginBundle\Model\IntegrationEntityModel;     // Para entidades de integração
use Psr\Log\LoggerInterface;                              // Para logs
use Symfony\Component\EventDispatcher\EventDispatcherInterface; // Para eventos
use Symfony\Component\HttpFoundation\RequestStack;        // Para requisições HTTP
use Symfony\Component\Routing\RouterInterface;            // Para roteamento
use Symfony\Contracts\Translation\TranslatorInterface;    // Para tradução

/**
 * Classe MauticEvolutionIntegration
 * 
 * Esta classe herda de AbstractIntegration, que fornece a base para todas
 * as integrações no Mautic. Ela define como o plugin se comporta e quais
 * funcionalidades oferece.
 */
class MauticEvolutionIntegration extends AbstractIntegration
{
    /**
     * Construtor da Integração
     * 
     * Recebe todas as dependências necessárias através de injeção de dependência.
     * Essas dependências são automaticamente fornecidas pelo sistema Mautic.
     */
    public function __construct(
        EventDispatcherInterface $dispatcher,              // Para disparar e escutar eventos
        CacheStorageHelper $cacheStorageHelper,            // Para gerenciar cache
        EntityManager $em,                                 // Para operações no banco de dados
        RequestStack $requestStack,                        // Para acessar dados da requisição
        RouterInterface $router,                           // Para gerar URLs
        TranslatorInterface $translator,                   // Para tradução de textos
        LoggerInterface $logger,                           // Para registrar logs
        EncryptionHelper $encryptionHelper,                // Para criptografar dados sensíveis
        LeadModel $leadModel,                              // Para trabalhar com contatos
        CompanyModel $companyModel,                        // Para trabalhar com empresas
        PathsHelper $pathsHelper,                          // Para caminhos de arquivos
        NotificationModel $notificationModel,              // Para notificações
        FieldModel $fieldModel,                            // Para campos customizados
        IntegrationEntityModel $integrationEntityModel,    // Para entidades de integração
        DoNotContactModel $doNotContact,                   // Para lista de não contatar
        FieldsWithUniqueIdentifier $fieldsWithUniqueIdentifier, // Para campos únicos
    ) {
        // Chama o construtor da classe pai passando todas as dependências
        parent::__construct(
            $dispatcher,
            $cacheStorageHelper,
            $em,
            $requestStack,
            $router,
            $translator,
            $logger,
            $encryptionHelper,
            $leadModel,
            $companyModel,
            $pathsHelper,
            $notificationModel,
            $fieldModel,
            $integrationEntityModel,
            $doNotContact,
            $fieldsWithUniqueIdentifier,
        );
    }
    /**
     * Método getName - Retorna o nome interno da integração
     * 
     * Este nome é usado internamente pelo Mautic para identificar a integração.
     * Deve ser único e não deve conter espaços ou caracteres especiais.
     */
    public function getName(): string
    {
        return 'MauticEvolution';    // Nome interno da integração
    }

    /**
     * Método getDisplayName - Retorna o nome que aparece na interface
     * 
     * Este é o nome que os usuários verão na interface do Mautic.
     * Pode conter espaços e caracteres especiais.
     */
    public function getDisplayName(): string
    {
        return 'Evolution Plugin';    // Nome exibido para os usuários
    }

    /**
     * @return string
     */
    public function getIcon(): string
    {
        return 'plugins/MauticEvolutionBundle/Assets/images/evolution-logo.png';
    }

    /**
     * Método getAuthenticationType - Define o tipo de autenticação
     * 
     * Especifica como a integração se autentica com serviços externos.
     * Opções: 'oauth1', 'oauth2', 'key', 'basic', 'none'
     */
    public function getAuthenticationType(): string
    {
        return 'none';    // Sem autenticação (para plugins simples)
    }

    /**
     * Método getRequiredKeyFields - Define campos obrigatórios para autenticação
     * 
     * Retorna um array com os nomes dos campos que são obrigatórios
     * para que a integração funcione (ex: API key, username, etc.)
     */
    public function getRequiredKeyFields(): array
    {
        return ['evolution_api_url', 'evolution_api_key'];    // Nenhum campo obrigatório (integração simples)
    }

    /**
     * Método getSecretKeys - Define quais campos contêm informações sensíveis
     * 
     * Campos listados aqui serão criptografados automaticamente pelo Mautic
     * antes de serem salvos no banco de dados.
     */
    public function getSecretKeys(): array
    {
        return ['evolution_api_key'];    // Nenhum campo secreto definido
    }

    /**
     * Método getAvailableLeadFields - Define campos disponíveis para mapeamento
     * 
     * Retorna um array com os campos que podem ser mapeados entre o Mautic
     * e o serviço externo. Usado para sincronização de dados de contatos.
     * 
     * @return array<string, mixed>
     */
    public function getAvailableLeadFields($settings = []): array
    {
        return [];    // Nenhum campo de mapeamento definido (integração simples)
    }

    /**
     * Método getFormSettings - Configurações do formulário de integração
     * 
     * Define configurações específicas sobre como o formulário de configuração
     * da integração deve se comportar na interface do Mautic.
     * 
     * @return array<string, mixed>
     */
    public function getFormSettings(): array
    {
        return [
            'requires_callback'      => false,    // Não precisa de URL de callback
            'requires_authorization' => false,    // Não precisa de autorização especial
        ];
    }

    /**
     * Adiciona campos customizados ao formulário da integração.
     * Permite expor configurações adicionais na área de 'features'.
     *
     * @param \Mautic\PluginBundle\Integration\Form|FormBuilder $builder
     * @param array                                                $data
     * @param string                                               $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' === $formArea) {
            $builder->add(
                'evolution_instance',
                \Symfony\Component\Form\Extension\Core\Type\TextType::class,
                [
                    'label' => 'mautic.evolution.config.instance',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'tooltip' => 'mautic.evolution.help.instance',
                    ],
                    'data' => $data['evolution_instance'] ?? 'default',
                    'required' => false,
                ]
            );
            $builder->add(
                'evolution_timeout',
                \Symfony\Component\Form\Extension\Core\Type\NumberType::class,
                [
                    'label' => 'mautic.evolution.config.timeout',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => ['class' => 'form-control'],
                    'data' => $data['evolution_timeout'] ?? 30,
                    'required' => false,
                ]
            );
            $builder->add(
                'evolution_country_code',
                \Symfony\Component\Form\Extension\Core\Type\TextType::class,
                [
                    'label' => 'mautic.evolution.config.country_code',
                    'label_attr' => ['class' => 'control-label'],
                    'attr' => [
                        'class' => 'form-control',
                        'tooltip' => 'mautic.evolution.help.country_code',
                    ],
                    'data' => $data['evolution_country_code'] ?? '55',
                    'required' => false,
                ]
            );
            $builder->add(
                'evolution_webhook_enabled',
                YesNoButtonGroupType::class,
                [
                    'label' => 'mautic.evolution.config.webhook_enabled',
                    'label_attr' => ['class' => 'control-label'],
                    'data' => isset($data['evolution_webhook_enabled']) ? (bool) $data['evolution_webhook_enabled'] : true,
                    'required' => false,
                ]
            );
            $builder->add(
                'check_whatsapp_on_save',
                YesNoButtonGroupType::class,
                [
                    'label'      => 'mautic.evolution.integration.check_whatsapp_on_save',
                    'label_attr' => ['class' => 'control-label'],
                    'attr'       => [
                        'class'   => 'form-control',
                        'tooltip' => 'mautic.evolution.integration.check_whatsapp_on_save.tooltip',
                    ],
                    'data'     => isset($data['check_whatsapp_on_save']) ? (bool) $data['check_whatsapp_on_save'] : false,
                    'required' => false,
                ]
            );
        }
    }

    /**
     * Método pushLead - Processa envio de contatos
     * 
     * Este método é chamado quando um contato precisa ser enviado para
     * o serviço externo. Aqui você implementaria a lógica específica
     * para processar e enviar os dados do contato.
     * 
     * @param array<string, mixed> $config Configurações da integração
     */
    public function pushLead($lead, $config = []): bool
    {
        // Implementação personalizada para processar leads
        // Aqui você adicionaria a lógica específica do seu plugin
        // Por exemplo: enviar dados para uma API externa, salvar em arquivo, etc.
        
        return true;    // Retorna true se o processamento foi bem-sucedido
    }
}