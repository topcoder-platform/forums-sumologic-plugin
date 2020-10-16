<?php
/**
 * Class SumologicPlugin
 */


use Garden\Schema\Schema;
use Garden\Web\Data;
use Garden\Web\Exception\ClientException;
use Garden\Web\Exception\NotFoundException;
use Vanilla\ApiUtils;
use Vanilla\Utility\ModelUtils;


class SumologicPlugin extends Gdn_Plugin {
    const PENDING_LOGS_KEY = 'SumologicPlugin.pendingLogs';
    private $pendingLogs;
    private $logSending;

    public function __construct() {
        parent::__construct();
        $this->logSending = false;
    }

    /**
     * Run once on enable.
     *
     */
    public function setup() {
        if(!c('Garden.Installed')) {
            return;
        }
    }

    public function onDisable() {
        if($this->isConfigured()) {
            $this->pendingLogs = apc_fetch(self::PENDING_LOGS_KEY);
            $this->sendLogs();
        }
        apc_store(self::PENDING_LOGS_KEY, null);
    }

    /**
     * Check if all required plugin settings is configured.
     *
     * @return bool True if the plugin is configured
     */
    public function isConfigured() {
        $httpSourceUrl = c('Plugins.Sumologic.HttpSourceURL', null);
        $batchSize = c('Plugins.Sumologic.HttpSourceURL');
        $isConfigured = isset($httpSourceUrl) &&  isset($batchSize);
        return $isConfigured;
    }

    /**
     * The settings page for the sumologic plugin.
     *
     * @param Gdn_Controller $sender
     */
    public function settingsController_sumologic_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->setData('Title', sprintf(t('%s Settings'), 'SumoLogic'));

        $cf = new ConfigurationModule($sender);

        // Form submission handling
        if(Gdn::request()->isAuthenticatedPostBack()) {
            $cf->form()->validateRule('Plugins.Sumologic.HttpSourceURL', 'ValidateRequired', t('You must provide Http Source URL.'));
            $cf->form()->validateRule('Plugins.Sumologic.HttpSourceURL', 'ValidateUrl','You must provide valid Http Source URL.');
            $cf->form()->validateRule('Plugins.Sumologic.BatchSize', 'ValidateRequired', t('You must provide Batch Size.'));
        }

        $cf->initialize([
            'Plugins.Sumologic.HttpSourceURL' => ['Control' => 'TextBox', 'Default' => '', 'Description' => 'SumoLogic Http Source URL'],
            'Plugins.Sumologic.BatchSize' => ['Control' => 'TextBox', 'Default' => '10', 'Description' => 'Batch Size'],
        ]);

        $cf->renderAll();
    }

    /** ------------------- SumoLogic Related Methods --------------------- */
    public function gdn_dispatcher_beforeDispatch_handler($sender, $args) {
        if(!c('Garden.Installed') || !$this->isConfigured()) {
            return;
        }

        $request = $args['Request'];

        $this->pendingLogs = apc_fetch(self::PENDING_LOGS_KEY);
        if(!$this->pendingLogs) {
            $this->pendingLogs = [];
        }
        $currentTime = new DateTime();
        $this->pendingLogs[] = $currentTime->format('Y-m-d\TH:i:s').' '.$request->getIP().' '.$request->getMethod(). ' '.$request->getUrl().' '.json_encode($request->getBody());
        $this->batchReadyToSend();
        apc_store(self::PENDING_LOGS_KEY, $this->pendingLogs);
    }

    private function batchReadyToSend() {
        $batchSize = (int) c('Plugins.Sumologic.BatchSize', 10);
        if(count($this->pendingLogs) >= $batchSize) {
            $this->sendLogs();
        }

    }

    /**
     * Send a batch of logs to SemiLogic
     */
    private function sendLogs() {
        if ($this->logSending || count($this->pendingLogs) === 0) {
            return;
        }

        try {
            $batchSize = (int) c('Plugins.Sumologic.BatchSize', 10);
            $messagesToSend = array_splice($this->pendingLogs, 0, $batchSize);
            $this->logSending = true;
            $content = implode(PHP_EOL, $messagesToSend);
            $options = array('http' => array(
                'method' => 'POST',
                'content' => $content
            ));

            $context = stream_context_create($options);
            $dataResponse = file_get_contents(c('Plugins.Sumologic.HttpSourceURL'), false, $context);
            if($dataResponse !== false) {
                $response = json_decode($dataResponse);
                $this->log('Sumologic response', ['response' => $response] , Logger::ERROR);
            }

        } catch (Exception $e) {
            $this->logSending = false;
            $this->log('Couldn\'t send data to SumoLogic', ['Error' => $e->getMessage()], Logger::ERROR);
        }

        $this->logSending = false;
    }

    public function log($message, $data, $level = Logger::INFO) {
        Logger::event(
            'sumo_logging',
            $level,
            $message,
            $data
        );
    }
}
