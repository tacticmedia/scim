<?php

declare(strict_types=1);

namespace TacticMedia\ScimDemo\IdentityProvider\Form;

use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use TacticMedia\ScimDemo\IdentityProvider\Entity\DirectoryUser;

/**
 * @extends AbstractType<DirectoryUser>
 */
final class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('userName', TextType::class, ['empty_data' => ''])
            ->add('givenName', TextType::class, ['empty_data' => ''])
            ->add('familyName', TextType::class, ['empty_data' => ''])
            ->add('displayName', TextType::class, ['empty_data' => ''])
            ->add('workEmail', EmailType::class, ['required' => false])
            ->add('mobilePhone', TelType::class, ['required' => false])
            ->add('department', TextType::class, ['required' => false])
            ->add('employeeNumber', TextType::class, ['required' => false])
            ->add('manager', EntityType::class, ['class' => DirectoryUser::class, 'choice_label' => 'userName', 'required' => false, 'placeholder' => 'No manager'])
            ->add('active', CheckboxType::class, ['required' => false])
            ->add('demoPlainTextPassword', TextType::class, ['required' => false, 'label' => 'Password (demo only: stored in plain text, Okta sends it on create)'])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => DirectoryUser::class]);
    }
}
