<?php namespace Brackets\AdminTranslations\Console\Commands;

use Brackets\AdminTranslations\Translation;
use Brackets\AdminTranslations\TranslationsScanner;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputArgument;

class ScanAndSave extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $name = 'admin-translations:scan-and-save';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scans all PHP files, extract translations and stores them into the database';

    protected function getArguments() {
        return [
            ['paths', InputArgument::IS_ARRAY, 'Array of paths to scan.', [app_path(), resource_path('views'), base_path('routes')]],
        ];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $scanner = app(TranslationsScanner::class);
        collect($this->argument('paths'))->each(function($path) use ($scanner){
            $scanner->addScannedPath($path);
        });
        //TODO change for vendor
        $scanner->addScannedPath(base_path('packages/Brackets/AdminAuth/src'));
        $scanner->addScannedPath(base_path('packages/Brackets/AdminAuth/resources'));

        list($trans, $__) = $scanner->getAllViewFilesWithTranslations();

        // TODO add test coverage for this command

        DB::transaction(function() use ($trans, $__){
            Translation::query()
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => Carbon::now()
                ]);

            $trans->each(function($trans){
                // TODO there was a better way in a themsaid package, check it out
                list($group, $key) = explode('.', $trans, 2);
                $namespaceAndGroup = explode('::', $group, 2);
                if(count($namespaceAndGroup) == 1) {
                    $namespace = '*';
                    $group = $namespaceAndGroup[0];
                } else {
                    list($namespace, $group) = $namespaceAndGroup;
                }
                $this->createOrUpdate($namespace, $group, $key);
            });

            $__->each(function($default){
                $this->createOrUpdate('*', '*', $default);
            });
        });

    }

    protected function createOrUpdate($namespace, $group, $key) {
        /** @var Translation $translation */
        $translation = Translation::withTrashed()
            ->where('namespace', $namespace)
            ->where('group', $group)
            ->where('key', $key)
            ->first();

        if ($translation) {
            $translation->restore();
        } else {
            Translation::create([
                'namespace' => $namespace,
                'group' => $group,
                'key' => $key,
                'text' => [],
            ]);
        }
    }
}