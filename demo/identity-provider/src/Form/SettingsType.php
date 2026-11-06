<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TacticMedia\ScimDemo\IdentityProvider\Entity\ProvisioningSettings;
use TacticMedia\ScimDemo\IdentityProvider\Provisioning\Dialect;

/**
 * @extends AbstractType<ProvisioningSettings>
 */
final class SettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('serviceProviderUrl', TextType::class, ['empty_data' => '', 'label' => 'Service provider SCIM base URL'])
            ->add('token', TextType::class, ['empty_data' => ''])
            ->add('dialect', EnumType::class, ['class' => Dialect::class, 'choice_label' => static fn (Dialect $dialect): string => $dialect->label()])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProvisioningSettings::class]);
    }
}
