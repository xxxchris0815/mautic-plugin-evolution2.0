<?php

declare(strict_types=1);

namespace MauticPlugin\MauticEvolutionBundle\Security\Permissions;

use Mautic\CoreBundle\Security\Permissions\AbstractPermissions;
use Symfony\Component\Form\FormBuilderInterface;

class EvolutionPermissions extends AbstractPermissions
{
    public function __construct($params)
    {
        parent::__construct($params);
        $this->addStandardPermissions('templates');
        $this->addStandardPermissions('messages');
    }

    public function getName(): string
    {
        return 'evolution';
    }

    public function buildForm(FormBuilderInterface &$builder, array $options, array $data): void
    {
        $this->addStandardFormFields('evolution', 'templates', $builder, $data);
        $this->addStandardFormFields('evolution', 'messages', $builder, $data);
    }
}
