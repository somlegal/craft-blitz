<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\drivers\deployers;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\db\Table;
use craft\events\CancelableEvent;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use GitWrapper\GitException;
use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\helpers\SiteUriHelper;
use Symfony\Component\Process\Process;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\InvalidArgumentException;

/**
 * @property mixed $settingsHtml
 */
class GitDeployer extends BaseDeployer
{
    // Constants
    // =========================================================================

    /**
     * @event CancelableEvent
     */
    const EVENT_BEFORE_COMMIT = 'beforeCommit';

    /**
     * @event Event
     */
    const EVENT_AFTER_COMMIT = 'afterCommit';

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('blitz', 'Git Deployer');
    }

    // Properties
    // =========================================================================

    /**
     * @var array
     */
    public $gitRepositories = [];

    /**
     * @var string
     */
    public $commitMessage = 'Blitz auto commit';

    /**
     * @var string|null
     */
    public $username;

    /**
     * @var string|null
     */
    public $personalAccessToken;

    /**
     * @var string|null
     */
    public $name;

    /**
     * @var string|null
     */
    public $email;

    /**
     * @var string
     */
    public $commandsBefore = '';

    /**
     * @var string
     */
    public $commandsAfter = '';

    /**
     * @var string
     */
    public $defaultBranch = 'master';

    /**
     * @var string
     */
    public $defaultRemote = 'origin';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['personalAccessToken'],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['username', 'personalAccessToken', 'name', 'email', 'commitMessage'], 'required'],
            [['email'], 'email'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function deployUrisWithProgress(array $siteUris, callable $setProgressHandler = null)
    {
        $count = 0;
        $total = 0;
        $label = 'Deploying {count} of {total} files.';

        $deployGroupedSiteUris = [];
        $groupedSiteUris = SiteUriHelper::getSiteUrisGroupedBySite($siteUris);

        foreach ($groupedSiteUris as $siteId => $siteUriGroup) {
            if ($this->_hasRepository($siteId)) {
                $deployGroupedSiteUris[$siteId] = $siteUriGroup;
                $total += count($siteUriGroup);
            }
        }

        if (is_callable($setProgressHandler)) {
            $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
            call_user_func($setProgressHandler, $count, $total, $progressLabel);
        }

        foreach ($deployGroupedSiteUris as $siteId => $siteUriGroup) {
            $repository = $this->_getRepository($siteId);

            if ($repository === null) {
                continue;
            }

            foreach ($siteUriGroup as $siteUri) {
                $count++;

                if (is_callable($setProgressHandler)) {
                    $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
                    call_user_func($setProgressHandler, $count, $total, $progressLabel);
                }

                $filePath = FileHelper::normalizePath($repository['repositoryPath'].'/'.$siteUri->uri.'/index.html');

                $value = Blitz::$plugin->cacheStorage->get($siteUri);

                $this->_updateFile($value, $filePath);
            }

            if (is_callable($setProgressHandler)) {
                $progressLabel = Craft::t('blitz', 'Deploying to remote.');
                call_user_func($setProgressHandler, $count, $total, $progressLabel);
            }

            $this->_deploy($siteId);
        }
    }

    /**
     * @inheritdoc
     */
    public function test(): bool
    {
        foreach ($this->gitRepositories as $siteUid => $gitRepository) {
            $repository = $this->_getRepositoryBySiteUid($siteUid);

            if ($repository === null) {
                continue;
            }

            if (!is_dir($repository['repositoryPath'])) {
                $this->addError('gitRepositories',
                    Craft::t('blitz',
                        'Repository path `{path}` is not a directory.',
                        ['path' => $repository['repositoryPath']]
                    )
                );
                continue;
            }

            if (!FileHelper::isWritable($repository['repositoryPath'])) {
                $this->addError('gitRepositories',
                    Craft::t('blitz',
                        'Repository path `{path}` is not writeable.',
                        ['path' => $repository['repositoryPath']]
                    )
                );
                continue;
            }

            try {
                $git = $this->_getGitWorkingCopy($repository['repositoryPath'], $repository['remote']);

                $git->fetch();
            }
            catch (GitException $e) {
                $this->addError('gitRepositories',
                    Craft::t('blitz',
                        'Error connecting to repository: {error}',
                        ['error' => $e->getMessage()]
                    )
                );
            }
        }

        return !$this->hasErrors();
    }

    /**
     * @inheritDoc
     */
    public function addError($attribute, $error = '')
    {
        // Remove value of personal access token to avoid it being output
        $error = str_replace($this->getPersonalAccessToken(), $this->personalAccessToken, $error);

        parent::addError($attribute, $error);
    }

    /**
     * @return string
     */
    public function getPersonalAccessToken(): string
    {
        $personalAccessToken = Craft::parseEnv($this->personalAccessToken);

        if (!is_string($personalAccessToken)) {
            return '';
        }

        return $personalAccessToken;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('blitz/_drivers/deployers/git/settings', [
            'deployer' => $this,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the repository for a given site UID
     *
     * @param int $siteId
     *
     * @return array|null
     */
    private function _getRepository(int $siteId)
    {
        $siteUid = Db::uidById(Table::SITES, $siteId);

        if ($siteUid === null) {
            return null;
        }

        return $this->_getRepositoryBySiteUid($siteUid);
    }

    /**
     * Returns the repository path for a given site UID
     *
     * @param string $siteUid
     *
     * @return array|null
     */
    private function _getRepositoryBySiteUid(string $siteUid)
    {
        $repository = $this->gitRepositories[$siteUid] ?? null;

        if (empty($repository)) {
            return null;
        }

        if (empty($repository['repositoryPath'])) {
            return null;
        }

        $repositoryPath = Craft::parseEnv($repository['repositoryPath']);

        if (!is_string($repositoryPath)) {
            return null;
        }

        $repository['repositoryPath'] = FileHelper::normalizePath($repositoryPath);
        $repository['branch'] = $repository['branch'] ?: $this->defaultBranch;
        $repository['remote'] = $repository['remote'] ?: $this->defaultRemote;

        return $repository;
    }

    /**
     * Returns whether the site has a writeable repository path
     *
     * @param int $siteId
     *
     * @return bool
     */
    private function _hasRepository(int $siteId): bool
    {
        $repository = $this->_getRepository($siteId);

        return $repository !== null;
    }

    /**
     * Returns a git working copy
     *
     * @param string $repositoryPath
     * @param string $remote
     *
     * @return GitWorkingCopy
     */
    private function _getGitWorkingCopy(string $repositoryPath, string $remote): GitWorkingCopy
    {
        $gitCommand = Blitz::$plugin->settings->commands['git'] ?? null;

        if ($gitCommand === null) {
            // Find the git binary (important because `ExecutableFinder` doesn't always find it!)
            $commands = [
                ['type', '-p', 'git'],
                ['which', 'git'],
            ];

            foreach ($commands as $command) {
                $process = new Process($command);
                $process->run();
                $gitCommand = trim($process->getOutput()) ?: null;

                if ($gitCommand !== null) {
                    break;
                }
            }
        }

        $gitWrapper = new GitWrapper($gitCommand);

        // Get working copy
        $git = $gitWrapper->workingCopy($repositoryPath);

        // Set user in config
        $git->config('user.name', $this->name);
        $git->config('user.email', $this->email);

        // Clear output (important!)
        // TODO: remove in Blitz 4 when GitWrapper 2 is forced
        if (method_exists($git, 'clearOutput')) {
            $git->clearOutput();
        }

        $remoteUrl = $git->getRemote($remote)['push'];

        // Break the URL into parts and reconstruct with personal access token
        $remoteUrl = (parse_url($remoteUrl, PHP_URL_SCHEME) ?: 'https').'://'
            .$this->username.':'.$this->getPersonalAccessToken().'@'
            .parse_url($remoteUrl, PHP_URL_HOST)
            .parse_url($remoteUrl, PHP_URL_PATH);

        $git->remote('set-url', $remote, $remoteUrl);

        return $git;
    }

    /**
     * Updates a file by saving the value or deleting the file if empty.
     *
     * @param string $value
     * @param string $filePath
     */
    private function _updateFile(string $value, string $filePath)
    {
        if (empty($value)) {
            if (file_exists($filePath)) {
                FileHelper::unlink($filePath);
            }

            return;
        }

        try {
            FileHelper::writeToFile($filePath, $value);
        }
        catch (ErrorException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
        catch (InvalidArgumentException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
    }

    /**
     * Deploys to the remote repository.
     *
     * @param int $siteId
     */
    private function _deploy(int $siteId)
    {
        $event = new CancelableEvent();
        $this->trigger(self::EVENT_BEFORE_COMMIT, $event);

        if (!$event->isValid) {
            return;
        }

        $this->_runCommands($this->commandsBefore);

        $repository = $this->_getRepository($siteId);

        if ($repository === null) {
            return;
        }

        try {
            $git = $this->_getGitWorkingCopy($repository['repositoryPath'], $repository['remote']);

            // Pull down any remote commits
            $git->pull();

            // Add all files to branch and check it out
            $git->add('*');
            $git->checkout($repository['branch']);

            // Check for changes first to avoid an exception being thrown
            if ($git->hasChanges()) {
                // Parse twig tags in the commit message
                $commitMessage = Craft::$app->getView()->renderString($this->commitMessage);

                $git->commit(addslashes($commitMessage));
            }

            $git->push();
        }
        catch (GitException $e) {
            Blitz::$plugin->log('Remote deploy failed: {error}', [
                'error' => $e->getMessage(),
            ], 'error');

            throw $e;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_COMMIT)) {
            $this->trigger(self::EVENT_AFTER_COMMIT, new Event());
        }

        $this->_runCommands($this->commandsAfter);
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs one or more commands.
     *
     * @param string|string[] $commands
     */
    private function _runCommands($commands)
    {
        if (empty($commands)) {
            return;
        }

        if (is_string($commands)) {
            $commands = preg_split('/\R/', $commands);
        }

        /** @var string $command */
        foreach ($commands as $command) {
            // TODO: remove condition in Blitz 4 when Process 4 is forced
            if (method_exists(Process::class, 'fromShellCommandline')) {
                $process = Process::fromShellCommandline($command);
            }
            else {
                /** @noinspection PhpParamsInspection */
                $process = new Process($command);
            }

            $process->mustRun();
        }
    }
}
