<?php

namespace MicroweberPackages\Package;

use MicroweberPackages\App\Models\SystemLicenses;
use MicroweberPackages\Package\Traits\FileDownloader;
use MicroweberPackages\Utils\Zip\Unzip;

class MicroweberComposerClient {

    use FileDownloader;

    public $logfile = false;
    public $licenses = [];
    public $packageServers = [
         'https://packages-satis.microweberapi.com/packages.json',
    ];

    public function __construct()
    {
        // Fill the user licenses
        $findLicenses = SystemLicenses::all();
        if ($findLicenses !== null) {
            $this->licenses = $findLicenses->toArray();
        }

        $this->logfile = userfiles_path() . 'install_item_log.txt';
    }

    public function search($filter = array())
    {
        $packages = [];
        foreach($this->packageServers as $package) {
            $getRepositories = $this->getPackageFile($package);

            if (empty($filter)) {
                return $getRepositories;
            }

            foreach($getRepositories as $packageName=>$packageVersions) {

                if (isset($filter['require_version'])) {
                    foreach ($packageVersions as $packageVersion => $packageVersionData) {
                        if (($filter['require_version'] == $packageVersion) &&
                            ($filter['require_name'] == $packageName)) {
                            $packages[] = $packageVersionData;
                            break;
                        }
                    }
                }

            }
        }

        return $packages;
    }

    public function requestInstall($params)
    {
        $this->newLog('Request install...');

        $this->log('Searching for '. $params['require_name'] . ' for version ' . $params['require_version']);

        $search = $this->search([
            'require_version'=>$params['require_version'],
            'require_name'=>$params['require_name'],
        ]);

        if (!$search) {
            return array('error' => 'Error. Cannot find any packages.');
        }

        $package = $search[0];

        $confirmKey = 'composer-confirm-key-' . rand();
        if (isset($params['confirm_key'])) {
            $isConfirmed = cache_get($params['confirm_key'], 'composer');
            if ($isConfirmed) {
                $package['unzipped_files_location'] = $isConfirmed['unzipped_files_location'];
                return $this->install($package);
            }
        }

        if ($package['dist']['type'] == 'license_key') {
            return array(
                'error' => _e('You need license key to install this package', true),
                'message' => _e('This package is premium and you must have a license key to install it', true),
                // 'form_data_required' => 'license_key',
                'form_data_module' => 'settings/group/license_edit',
                'form_data_module_params' => array(
                    'require_name' => $params['require_name'],
                    'require_version' => _e('You need license key', true)
                )
            );
        }

        $this->downloadPackage($package, $confirmKey);
        $this->clearLog();

        return array(
            'error' => 'Please confirm installation',
            'form_data_module' => 'admin/developer_tools/package_manager/confirm_install',
            'form_data_module_params' => array(
                'confirm_key' => $confirmKey,
                'require_name' => $params['require_name'],
                'require_version' => $params['require_version']
            )
        );
    }

    public function downloadPackage($package, $confirmKey)
    {
        if (isset($package['dist']['url'])) {

            $distUrl = $package['dist']['url'];

            if (!isset($package['target-dir'])) {
                return false;
            }

            $packageFileName = 'last-package.zip';
            $packageFileDestination = storage_path() . '/cache/composer-download/' . $package['target-dir'] .'/';

            rmdir_recursive($packageFileDestination); // remove dir
            mkdir_recursive($packageFileDestination); // make new dir

            $this->log('Downloading the package file..');

            $downloadStatus = $this->downloadBigFile($distUrl, $packageFileDestination . $packageFileName, $this->logfile);

            if ($downloadStatus) {

                $this->log('Extract the package file..');

                $unzip = new Unzip();
                $unzip->extract($packageFileDestination . $packageFileName, $packageFileDestination, true);

                // Delete zip file
                @unlink($packageFileDestination . $packageFileName);

                $scanDestination = $this->recursiveScan($packageFileDestination);
                foreach ($scanDestination as $key => $value) {
                    $this->log('Unzip file: ' . $value);
                }

                $composerConfirm = array();
                $composerConfirm['user'] = $scanDestination;
                $composerConfirm['packages'] = $scanDestination;
                $composerConfirm['unzipped_files_location'] = $packageFileDestination;

                cache_save($composerConfirm, $confirmKey, 'composer');

                return true;
            }
        }

        return false;
    }

    public function recursiveScan($dir){

        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        $files = array();
        foreach ($directory as $info) {
            $files[] = $info->getFilename();
        }

        return $files;
    }

    public function install($package)
    {
        $type = 'microweber-module';
            if (isset($package['type'])) {
                $type = $package['type'];
            }

       if ($type == 'microweber-module') {
            $packageFileDestination = userfiles_path() .'/modules/'.$package['target-dir'].'/';
        }

        if ($type == 'microweber-template') {
            $packageFileDestination = userfiles_path() .'/templates/'.$package['target-dir'].'/';
        }

        if (!isset($package['unzipped_files_location'])) {
            return false;
        }

        rmdir_recursive($packageFileDestination); // remove dir

        @rename($package['unzipped_files_location'],$packageFileDestination);

        $response = array();
        $response['success'] = 'Success. You have installed: ' . $package['name'] . ' .  Total files installed';
        $response['log'] = 'Done!';

        // app()->update->post_update();
        scan_for_modules('skip_cache=1&cleanup_db=1&reload_modules=1');

        return $response;
    }

    public function newLog($log)
    {
        @file_put_contents($this->logfile, $log . PHP_EOL);
    }

    public function clearLog()
    {
        @file_put_contents($this->logfile, '');
    }

    public function log($log)
    {
        @file_put_contents($this->logfile, $log . PHP_EOL, FILE_APPEND);
    }

    public function getPackageFile($packagesUrl)
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $packagesUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_POSTFIELDS => "",
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode(json_encode($this->licenses))
            ],
        ]);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            return ["error"=>"cURL Error #:" . $err];
        } else {
            $getPackages = json_decode($response, true);
            if (isset($getPackages['packages']) && is_array($getPackages['packages'])) {
                return $getPackages['packages'];
            }
            return [];
        }
    }

}
