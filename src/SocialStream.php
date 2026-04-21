<?php

namespace enovate\socialstream;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\log\MonologTarget;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use enovate\socialstream\models\Settings;
use enovate\socialstream\providers\InstagramProvider;
use enovate\socialstream\services\CacheService;
use enovate\socialstream\services\Providers;
use enovate\socialstream\services\TokenService;
use enovate\socialstream\variables\SocialStreamVariable;
use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use yii\base\Event;
use yii\log\Dispatcher;

/**
 * Social Stream plugin for Craft CMS 5
 *
 * Pull posts from Instagram (and other social platforms) into Craft CMS templates.
 *
 * @property Providers $providers
 * @property TokenService $token
 * @property CacheService $streamCache
 */
class SocialStream extends Plugin
{
    public static SocialStream $plugin;

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerLogTarget();
        $this->_registerTwigVariable();
        $this->_registerCacheTag();
        $this->_registerSiteUrlRules();
        $this->_registerCpUrlRules();
        $this->_registerAfterInstallRedirect();
        $this->_registerDefaultProviders();

        Craft::info(
            Craft::t('social-stream', '{name} plugin loaded', ['name' => $this->name]),
            __METHOD__
        );
    }

    public static function info(string $message): void
    {
        Craft::info($message, 'social-stream');
    }

    public static function warning(string $message): void
    {
        Craft::warning($message, 'social-stream');
    }

    public static function error(string $message): void
    {
        Craft::error($message, 'social-stream');
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->redirect(UrlHelper::cpUrl('social-stream/settings'));
    }

    protected function createSettingsModel(): ?Settings
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('social-stream/settings/index');
    }

    /**
     * Merge config/social-stream.php overrides over the plugin settings.
     */
    public function getSettings(): ?Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        if ($settings === null) {
            return null;
        }

        $configFile = Craft::$app->config->getConfigFromFile('social-stream');

        if (!empty($configFile)) {
            foreach ($configFile as $key => $value) {
                if (property_exists($settings, $key)) {
                    $settings->$key = $value;
                }
            }
        }

        return $settings;
    }

    private function _registerLogTarget(): void
    {
        if (Craft::getLogger()->dispatcher instanceof Dispatcher) {
            Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
                'name' => 'social-stream',
                'categories' => ['social-stream'],
                'level' => LogLevel::INFO,
                'logContext' => false,
                'allowLineBreaks' => false,
                'formatter' => new LineFormatter(
                    format: "[%datetime%] %message%\n",
                    dateFormat: 'Y-m-d H:i:s',
                ),
            ]);
        }
    }

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('socialStream', SocialStreamVariable::class);
            }
        );
    }

    private function _registerCacheTag(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_TAG_OPTIONS,
            function (RegisterCacheOptionsEvent $event) {
                $event->options[] = [
                    'tag' => 'social-stream',
                    'label' => Craft::t('social-stream', 'Social Stream data'),
                ];
            }
        );
    }

    private function _registerSiteUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['social-stream/auth/callback'] = 'social-stream/auth/callback';
            }
        );
    }

    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules = array_merge($event->rules, [
                    'social-stream' => 'social-stream/settings/index',
                    'social-stream/settings' => 'social-stream/settings/index',
                    'social-stream/settings/<siteHandle:{handle}>' => 'social-stream/settings/index',
                ]);
            }
        );
    }

    private function _registerAfterInstallRedirect(): void
    {
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    $request = Craft::$app->getRequest();
                    if ($request->isCpRequest) {
                        Craft::$app->getResponse()
                            ->redirect(UrlHelper::cpUrl('social-stream/settings'))
                            ->send();
                    }
                }
            }
        );
    }

    private function _registerDefaultProviders(): void
    {
        Event::on(
            Providers::class,
            Providers::EVENT_REGISTER_PROVIDER_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = InstagramProvider::class;
            }
        );
    }
}
