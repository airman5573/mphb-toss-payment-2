<?php
namespace MPHB\Payments\Gateways\Toss\Admin; // Corrected namespace

use MPHB\Admin\Fields\FieldFactory;
use MPHB\Admin\Groups;
use MPHB\Payments\Gateways\TossGateway; // Corrected use statement target namespace
// Removed use statement for non-existent TossStatusChecker

/**
 * Handles Toss Payments admin settings registration and display.
 */
class TossAdminSetup { // Renamed class

    private $gateway;
    // Removed $statusChecker property

    /**
     * Constructor
     * 
     * @param \MPHB\Payments\Gateways\TossGateway $gateway Toss Gateway instance (using FQCN for clarity)
     */
    public function __construct(
        \MPHB\Payments\Gateways\TossGateway $gateway // Using FQCN for clarity
        // Removed TossStatusChecker parameter
    ) {
        $this->gateway = $gateway;
        // Removed assignment for $statusChecker
    }

    // --- Field Creation Methods (from TossAdminFieldProvider) ---

    /**
     * Creates main settings group fields.
     * 
     * @return array Field array
     */
    private function createMainGroupFields(): array {
        return [
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_title", [
                'type' => 'text', 
                'label' => __('Title', 'motopress-hotel-booking'),
                'default' => 'Toss Payments',
                'translatable' => true,
            ]),
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_description", [
                'type' => 'textarea', 
                'label' => __('Description', 'motopress-hotel-booking'),
                'default' => __('Pay with Toss Payments.', 'motopress-hotel-booking'),
                'translatable' => true,
            ]),
        ];
    }

    /**
     * Creates API settings group fields.
     * 
     * @return array Field array
     */
    private function createApiGroupFields(): array {
        return [
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_client_key", [
                'type' => 'text', 
                'label' => __('Client Key', 'mphb-toss'),
                'default' => '',
                'description' => __('Enter your Toss Payments Client Key.', 'mphb-toss'),
            ]),
            FieldFactory::create("mphb_payment_gateway_{$this->gateway->getId()}_secret_key", [
                'type' => 'text', 
                'label' => __('Secret Key', 'mphb-toss'),
                'default' => '',
                'description' => __('Enter your Toss Payments Secret Key.', 'mphb-toss'),
            ]),
        ];
    }

    // --- Settings Registration & Display Methods (from TossAdminRegistrar) ---

    /**
     * Registers option fields in the admin settings screen.
     * 
     * @param object $subTab Settings tab object
     */
    public function registerOptionsFields(&$subTab): void {
        $this->addSettingsGroup(
            $subTab, 'main', '', $this->createMainGroupFields() // Call internal method
        );
        $this->addSettingsGroup(
            $subTab, 'api', __('API Settings', 'mphb-toss'), $this->createApiGroupFields() // Call internal method
        );
    }

    /**
     * Helper to add a settings group.
     * 
     * @param object $subTab Settings tab object
     * @param string $groupIdSuffix Group ID suffix
     * @param string $groupTitle Group title
     * @param array $fields Field array
     */
    private function addSettingsGroup(&$subTab, string $groupIdSuffix, string $groupTitle, array $fields): void {
        if (empty($fields)) return;
        
        $group = new Groups\SettingsGroup(
            "mphb_payments_{$this->gateway->getId()}_{$groupIdSuffix}",
            $groupTitle,
            $subTab->getOptionGroupName()
        );
        
        $group->addFields($fields);
        $subTab->addGroup($group);
    }

    /**
     * Generates the admin description HTML.
     * 
     * @return string Admin description HTML
     */
    public function getAdminDescription(): string {
        if ($this->gateway->isActive()) {
            return __('Toss Payments gateway is active.', 'mphb-toss');
        } else {
            $keysExist = !empty($this->gateway->getClientKey()) && !empty($this->gateway->getSecretKey());
            $currencyCode = $this->getCurrencyCode();
            $currencyIsKRW = ('KRW' === strtoupper($currencyCode));

            if (!$keysExist) {
                return __('Requires Client Key and Secret Key to be enabled.', 'mphb-toss');
            } elseif (!$currencyIsKRW) {
                return sprintf(
                    '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
                    sprintf(
                        esc_html__('%1$s requires the store currency to be %2$s. Current currency is %3$s. The gateway is inactive.', 'mphb-toss'),
                        $this->gateway->getAdminTitle(), 'KRW', esc_html($currencyCode)
                    )
                );
            } else {
                return __('Gateway is currently inactive.', 'mphb-toss');
            }
        }
    }

    /**
     * Hides the admin option if it's for this gateway.
     * 
     * @param bool $show Show status
     * @param string $gatewayId Gateway ID
     * @return bool Modified show status
     */
    public function hideAdminOption(bool $show, string $gatewayId): bool {
        return ($gatewayId === $this->gateway->getId()) ? false : $show;
    }

    /**
     * Gets the currently configured currency code.
     * 
     * @return string Currency code
     */
    private function getCurrencyCode(): string {
        if (function_exists('MPHB') && is_object(MPHB()->settings()->currency())) {
            return MPHB()->settings()->currency()->getCurrencyCode();
        }
        
        return '';
    }
}

// Note: References to the non-existent TossStatusChecker class have been removed.
