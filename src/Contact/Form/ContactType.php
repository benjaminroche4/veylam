<?php

declare(strict_types=1);

namespace App\Contact\Form;

use App\Contact\Entity\Contact;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'contact.form.name',
            ])
            ->add('email', EmailType::class, [
                'label' => 'contact.form.email',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'contact.form.message',
            ])
            // Honeypot: hidden from humans, bots fill it in
            ->add('website', TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'contact.form.website',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}
