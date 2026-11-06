<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryGroup;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * @extends AbstractType<DirectoryGroup>
 */
final class GroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, ['empty_data' => ''])
            ->add('members', EntityType::class, ['class' => DirectoryUser::class, 'choice_label' => 'userName', 'multiple' => true, 'expanded' => true, 'required' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DirectoryGroup::class]);
    }
}
