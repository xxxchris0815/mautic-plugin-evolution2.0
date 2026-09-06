<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Model;

use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplate;
use MauticPlugin\MauticEvolutionBundle\Entity\EvolutionTemplateRepository;
use MauticPlugin\MauticEvolutionBundle\Form\Type\EvolutionTemplateType;
use Mautic\CoreBundle\Model\FormModel;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Translation\Translator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\Event;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class TemplateModel
 * 
 * Modelo para gerenciar templates do Evolution API
 */
class TemplateModel extends FormModel
{
    public function __construct(
        EntityManagerInterface $em,
        CorePermissions $security,
        EventDispatcherInterface $dispatcher,
        UrlGeneratorInterface $router,
        Translator $translator,
        UserHelper $userHelper,
        LoggerInterface $mauticLogger,
        CoreParametersHelper $coreParametersHelper
    ) {
        parent::__construct($em, $security, $dispatcher, $router, $translator, $userHelper, $mauticLogger, $coreParametersHelper);
    }

    /**
     * {@inheritdoc}
     */
    public function getRepository(): EvolutionTemplateRepository
    {
        return $this->em->getRepository(EvolutionTemplate::class);
    }

    /**
     * {@inheritdoc}
     */
    public function getPermissionBase(): string
    {
        return 'evolution:templates';
    }

    /**
     * {@inheritdoc}
     */
    public function createForm($entity, FormFactoryInterface $formFactory, $action = null, $options = []): FormInterface
    {
        if (!$entity instanceof EvolutionTemplate) {
            throw new MethodNotAllowedHttpException(['EvolutionTemplate']);
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(EvolutionTemplateType::class, $entity, $options);
    }

    /**
     * {@inheritdoc}
     */
    public function getEntity($id = null): ?EvolutionTemplate
    {
        if (null === $id) {
            return new EvolutionTemplate();
        }

        return parent::getEntity($id);
    }

    /**
     * {@inheritdoc}
     */
    protected function dispatchEvent($action, &$entity, $isNew = false, Event $event = null): ?Event
    {
        if (!$entity instanceof EvolutionTemplate) {
            throw new MethodNotAllowedHttpException(['EvolutionTemplate']);
        }

        switch ($action) {
            case 'pre_save':
                $name = 'evolution.template_pre_save';
                break;
            case 'post_save':
                $name = 'evolution.template_post_save';
                break;
            case 'pre_delete':
                $name = 'evolution.template_pre_delete';
                break;
            case 'post_delete':
                $name = 'evolution.template_post_delete';
                break;
            default:
                return null;
        }

        if ($this->dispatcher->hasListeners($name)) {
            if (empty($event)) {
                // Criar evento básico por enquanto
                $event = new \Symfony\Contracts\EventDispatcher\Event();
            }

            $this->dispatcher->dispatch($event, $name);

            return $event;
        }

        return null;
    }

    /**
     * Extrai variáveis do conteúdo do template
     */
    public function extractTemplateVariables(string $content): array
    {
        // Implementação direta aqui em vez de chamar método estático
        preg_match_all('/\{\{([^}]+)\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Busca templates ativos
     */
    public function getActiveTemplates(): array
    {
        return $this->getRepository()->findActiveTemplates();
    }

    /**
     * Busca templates por tipo
     */
    public function getTemplatesByType(string $type): array
    {
        return $this->getRepository()->findByType($type);
    }

    /**
     * Busca template por nome
     */
    public function getTemplateByName(string $name): ?EvolutionTemplate
    {
        return $this->getRepository()->findByName($name);
    }

    public function getTemplateByNameAndLanguage(string $name, ?string $language = null): ?EvolutionTemplate
    {
        return $this->getRepository()->findByNameAndLanguage($name, $language);
    }

    /**
     * Retorna opções de templates para select
     */
    public function getTemplateChoices(): array
    {
        return $this->getRepository()->getTemplateChoices();
    }

    /**
     * Valida se o nome do template já existe
     */
    public function templateNameExists(string $name, ?int $excludeId = null): bool
    {
        return $this->getRepository()->templateNameExists($name, $excludeId);
    }

    /**
     * Renderiza template com dados do lead
     */
    public function renderTemplate(EvolutionTemplate $template, array $leadData = []): string
    {
        $content = $template->getContent();
        
        // Substitui variáveis no template
        foreach ($leadData as $key => $value) {
            $content = str_replace('{' . $key . '}', (string) $value, $content);
        }
        
        return $content;
    }

    /**
     * Valida variáveis do template
     */
    public function validateTemplateVariables(EvolutionTemplate $template, array $leadData = []): array
    {
        $content = $template->getContent();
        $errors = [];
        
        // Encontra todas as variáveis no template
        preg_match_all('/\{([^}]+)\}/', $content, $matches);
        
        if (!empty($matches[1])) {
            foreach ($matches[1] as $variable) {
                if (!isset($leadData[$variable])) {
                    $errors[] = "Variable '{$variable}' not found in lead data";
                }
            }
        }
        
        return $errors;
    }

    /**
     * Duplica um template
     */
    public function duplicateTemplate(EvolutionTemplate $template): EvolutionTemplate
    {
        $newTemplate = new EvolutionTemplate();
        $newTemplate->setName($template->getName() . ' (Cópia)');
        $newTemplate->setDescription($template->getDescription());
        $newTemplate->setContent($template->getContent());
        $newTemplate->setType($template->getType());
        $newTemplate->setVariables($template->getVariables());
        $newTemplate->setIsActive(false); // Inicia como inativo
        $newTemplate->setMetadata($template->getMetadata());

        $this->saveEntity($newTemplate);

        return $newTemplate;
    }

    /**
     * Ativa/desativa template
     */
    public function toggleTemplateStatus(EvolutionTemplate $template): EvolutionTemplate
    {
        $template->setIsActive(!$template->isActive());
        $this->saveEntity($template);

        return $template;
    }

    /**
     * Busca templates com paginação
     *
     * @param array $args
     * @return \Doctrine\ORM\Tools\Pagination\Paginator|array
     */
    public function getEntities(array $args = [])
    {
        return $this->getRepository()->getEntities($args);
    }

    /**
     * Conta total de templates
     */
    public function getEntityCount(): int
    {
        return $this->getRepository()->countEntities();
    }

    /**
     * Busca templates por filtros
     */
    public function getTemplatesByFilters(array $filters = []): array
    {
        $qb = $this->getRepository()->createQueryBuilder('t');

        // Filtro por nome
        if (!empty($filters['name'])) {
            $qb->andWhere('t.name LIKE :name')
               ->setParameter('name', '%' . $filters['name'] . '%');
        }

        // Filtro por tipo
        if (!empty($filters['type'])) {
            $qb->andWhere('t.type = :type')
               ->setParameter('type', $filters['type']);
        }

        // Filtro por status
        if (isset($filters['is_active'])) {
            $qb->andWhere('t.isActive = :isActive')
               ->setParameter('isActive', (bool) $filters['is_active']);
        }

        // Ordenação
        $orderBy = $filters['orderBy'] ?? 'name';
        $orderDir = $filters['orderDir'] ?? 'ASC';
        $qb->orderBy('t.' . $orderBy, $orderDir);

        return $qb->getQuery()->getResult();
    }

    /**
     * Importa templates de um array
     */
    public function importTemplates(array $templatesData): array
    {
        $imported = [];
        $errors = [];

        foreach ($templatesData as $templateData) {
            try {
                // Verifica se já existe template com o mesmo nome
                if ($this->templateNameExists($templateData['name'])) {
                    $errors[] = sprintf('Template "%s" já existe', $templateData['name']);
                    continue;
                }

                $template = new EvolutionTemplate();
                $template->setName($templateData['name']);
                $template->setDescription($templateData['description'] ?? '');
                $template->setContent($templateData['content']);
                $template->setType($templateData['type'] ?? 'text');
                $template->setIsActive($templateData['is_active'] ?? true);
                
                if (!empty($templateData['metadata'])) {
                    $template->setMetadata($templateData['metadata']);
                }

                $this->saveEntity($template);
                $imported[] = $template;

            } catch (\Exception $e) {
                $errors[] = sprintf('Erro ao importar template "%s": %s', 
                    $templateData['name'] ?? 'Desconhecido', 
                    $e->getMessage()
                );
            }
        }

        return [
            'imported' => $imported,
            'errors' => $errors,
        ];
    }

    /**
     * Exporta templates para array
     */
    public function exportTemplates(array $templateIds = []): array
    {
        $qb = $this->getRepository()->createQueryBuilder('t');

        if (!empty($templateIds)) {
            $qb->andWhere('t.id IN (:ids)')
               ->setParameter('ids', $templateIds);
        }

        $templates = $qb->getQuery()->getResult();
        $exported = [];

        foreach ($templates as $template) {
            $exported[] = [
                'name' => $template->getName(),
                'description' => $template->getDescription(),
                'content' => $template->getContent(),
                'type' => $template->getType(),
                'is_active' => $template->getIsActive(),
                'variables' => $template->getVariables(),
                'metadata' => $template->getMetadata(),
            ];
        }

        return $exported;
    }
}