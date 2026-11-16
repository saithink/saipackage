<?php
namespace plugin\saipackage\command;

use plugin\saiadmin\utils\PhinxRunner;
use plugin\saipackage\app\logic\PluginMigrator;
use Saithink\Saipackage\management\Factory;
use Saithink\Saipackage\management\Frontend;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class Setup extends Command
{
    protected static $defaultName = 'saipackage:setup';
    protected function configure(): void
    {
        $this->setName('saipackage:setup')->setDescription('Install management tables and import existing plugins without reinstalling them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migrator = new PluginMigrator('saipackage');
        $result = PhinxRunner::run('migrate', [], $migrator->config());
        $output->write($result['output']);
        if (!$result['ok']) return self::FAILURE;
        $manager = Factory::manager();
        $lock = $manager->lock();
        $manager->importLegacy();
        $frontend = dirname(base_path()) . '/' . env('FRONTEND_DIR', 'saiadmin-artd');
        $output->writeln(Frontend::install($frontend) ? 'Management panel installed. Rebuild your frontend.' : 'Frontend absent; see integration instructions.');
        $output->writeln('Management tables ready. Configure the stable settings key, enable management and restart.');
        return self::SUCCESS;
    }
}
