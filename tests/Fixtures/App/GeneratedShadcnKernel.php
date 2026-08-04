<?php

declare(strict_types=1);

namespace Te4g\UserBundle\Tests\Fixtures\App;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Symfonycasts\TailwindBundle\SymfonycastsTailwindBundle;
use TalesFromADev\Twig\Extra\Tailwind\TailwindExtension;
use TalesFromADev\Twig\Extra\Tailwind\TailwindRuntime;
use Te4g\UserBundle\Form\DeleteAccountType;
use Te4g\UserBundle\Form\EmailType;
use Te4g\UserBundle\Form\PasswordChangeType;
use Te4g\UserBundle\Form\ProfileType;
use Te4g\UserBundle\Form\RegistrationType;
use Te4g\UserBundle\Form\ResetPasswordType;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

final class GeneratedShadcnKernel extends Kernel
{
    use MicroKernelTrait;

    private readonly string $projectDirectory;
    private readonly string $tailwindBinary;

    public function __construct(
        string $projectDirectory,
        ?string $tailwindBinary,
        ?PinnedTailwindBinary $tailwindValidator = null,
    ) {
        $this->projectDirectory = $projectDirectory;
        $this->tailwindBinary = ($tailwindValidator ?? PinnedTailwindBinary::tailwind433())->validate($tailwindBinary);
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new TwigBundle(),
            new TwigExtraBundle(),
            new StimulusBundle(),
            new TurboBundle(),
            new TwigComponentBundle(),
            new UXIconsBundle(),
            new SymfonycastsTailwindBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return $this->projectDirectory;
    }

    public function getCacheDir(): string
    {
        return $this->projectDirectory.'/var/cache';
    }

    public function getLogDir(): string
    {
        return $this->projectDirectory.'/var/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $projectDirectory = $this->projectDirectory;
        $tailwindBinary = $this->tailwindBinary;
        $loader->load(static function (ContainerBuilder $container) use ($projectDirectory, $tailwindBinary): void {
            $container->loadFromExtension('framework', [
                'secret' => 'generated-shadcn-secret',
                'test' => true,
                'default_locale' => 'en',
                'router' => [
                    'resource' => __DIR__.'/generated_shadcn_routes.php',
                    'type' => 'php',
                    'utf8' => true,
                ],
                'form' => ['enabled' => true],
                'validation' => ['enabled' => true],
                'csrf_protection' => true,
                'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'translator' => [
                    'paths' => [$projectDirectory.'/translations'],
                    'fallbacks' => ['en'],
                ],
                'asset_mapper' => [
                    'paths' => [$projectDirectory.'/assets'],
                    'importmap_path' => $projectDirectory.'/importmap.php',
                    'importmap_polyfill' => false,
                    'vendor_dir' => $projectDirectory.'/assets/vendor',
                    'missing_import_mode' => 'strict',
                ],
            ]);
            $container->loadFromExtension('twig', [
                'default_path' => $projectDirectory.'/templates',
                'form_themes' => ['form/te4g_user_shadcn_theme.html.twig'],
                'strict_variables' => true,
            ]);
            $container->loadFromExtension('stimulus', [
                'controller_paths' => [$projectDirectory.'/assets/controllers'],
                'controllers_json' => $projectDirectory.'/assets/controllers.json',
            ]);
            $container->loadFromExtension('turbo', ['broadcast' => ['enabled' => false]]);
            $container->loadFromExtension('twig_component', [
                'anonymous_template_directory' => 'components',
                'defaults' => [
                    'GeneratedApp\\Twig\\Components\\' => ['template_directory' => 'components'],
                ],
            ]);
            $container->loadFromExtension('ux_icons', [
                'icon_dir' => $projectDirectory.'/assets/icons',
                'iconify' => ['enabled' => false],
            ]);
            $tailwind = [
                'input_css' => [$projectDirectory.'/assets/styles/app.css'],
                'strict_mode' => true,
                'process_timeout' => 60,
            ];
            $tailwind['binary'] = $tailwindBinary;
            $container->loadFromExtension('symfonycasts_tailwind', $tailwind);

            $container->register(TailwindExtension::class, TailwindExtension::class)->addTag('twig.extension');
            $container->register(TailwindRuntime::class, TailwindRuntime::class)->addTag('twig.runtime');
            $container->setAlias('test.form_factory', 'form.factory')->setPublic(true);
            $container->setAlias('test.request_stack', 'request_stack')->setPublic(true);
            $container->setAlias('test.twig', 'twig')->setPublic(true);
            foreach ([
                DeleteAccountType::class,
                EmailType::class,
                PasswordChangeType::class,
                ProfileType::class,
                RegistrationType::class,
                ResetPasswordType::class,
            ] as $formType) {
                $container->register($formType, $formType)->addTag('form.type');
            }
        });
    }
}
