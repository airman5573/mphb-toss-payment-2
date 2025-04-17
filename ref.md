## Class: MPHB\Entities\Booking

*   `__construct`(`array` `$atts`) → `void`
*   `create`(`array` `$atts`) → `Booking`
*   `setupParameters`(`array` `$atts` = `array()`) → `void` (protected)
*   `setStatus`(`string` `$status`) → `void`
*   `generateKey`() → `string`
*   `setDates`(`\DateTime` `$checkInDate`, `\DateTime` `$checkOutDate`) → `void`
*   `getDateTime`() → `\DateTime`
*   `setRooms`(`array` `$rooms`) → `void` *(Note: Doc indicates $rooms is `ReservedRoom[]`)*
*   `updateTotal`() → `void`
*   `getLastPriceBreakdown`(`bool` `$load` = `true`) → `array|null`
*   `getPriceBreakdown`() → `array`
*   `calcPrice`() → `float`
*   `calcDepositAmount`(`float|null` `$total` = `null`) → `float`
*   `addLog`(`string` `$message`, `int` `$author` = `null`) → `void`
*   `getLogs`() → `array` *(Note: Returns array of comment objects)*
*   `getId`() → `int`
*   `setId`(`int` `$id`) → `void`
*   `getKey`() → `string`
*   `getCheckInDate`() → `\DateTime`
*   `getCheckOutDate`() → `\DateTime`
*   `getNightsCount`() → `int`
*   `getReservedRooms`() → `array` *(Note: Returns `ReservedRoom[]`)*
*   `getReservedRoomIds`() → `array` *(Note: Returns `int[]`)*
*   `getReservedRoomTypeIds`() → `array` *(Note: Returns `int[]`)*
*   `getRoomIds`() → `array` *(Note: Returns `int[]`)*
*   `setCustomer`(`Customer` `$customer`) → `void`
*   `getCustomer`() → `Customer`
*   `getNote`() → `string`
*   `setNote`(`string` `$note`) → `void`
*   `getTotalPrice`() → `float`
*   `getStatus`() → `string`
*   `getDates`(`bool` `$fromToday` = `false`) → `array`
*   `updateExpiration`(`string` `$type`, `int` `$expirationTime`) → `void`
*   `retrieveExpiration`(`string` `$type`) → `int`
*   `deleteExpiration`(`string` `$type`) → `void`
*   `getICalProdid`() → `string`
*   `getICalSummary`() → `string|null`
*   `getICalDescription`() → `string|null`
*   `getLanguage`() → `string`
*   `isExpectPayment`(`int` `$paymentId`) → `bool`
*   `setExpectPayment`(`int` `$paymentId`) → `void`
*   `getExpectPaymentId`() → `int|false`
*   `applyCoupon`(`Coupon` `$coupon`) → `bool|\WP_Error`
*   `getCouponCode`() → `string`
*   `getCouponId`() → `int`
*   `getCheckoutId`() → `string`
*   `getSyncId`() → `string`
*   `getSyncQueueId`() → `int`
*   `getInternalNotes`() → `array`
*   `isImported`() → `bool`
*   `isPending`() → `bool`

## Class: MPHB\Entities\Customer

*   `__construct`(`array` `$atts` = `array()`) → `void`
*   `getCustomerId`() → `int`
*   `setCustomerId`(`int` `$id`) → `void`
*   `getEmail`() → `string`
*   `setEmail`(`string` `$email`) → `void`
*   `hasEmail`() → `bool`
*   `getName`() → `string`
*   `getFirstName`() → `string`
*   `getLastName`() → `string`
*   `getPhone`() → `string`
*   `getCountry`() → `string`
*   `getState`() → `string`
*   `getCity`() → `string`
*   `getZip`() → `string`
*   `getAddress1`() → `string`
*   `getCustomFields`() → `array`
*   `getCustomField`(`string` `$fieldName`) → `mixed`

## Class: MPHB\Session

*   `__construct`() → `void`
*   `init`() → `\MPHB\Libraries\WP_SessionManager\WP_Session`
*   `get_id`() → `string`
*   `get`(`string` `$key`) → `string|null`
*   `set`(`string` `$key`, `mixed` `$value`) → `void`
*   `toArray`() → `array`

## Class: MPHB\Payments\Gateways\GatewayManager

*   `__construct`() → `void`
*   `registerGateways`() → `void`
*   `initPrebuiltGateways`() → `void`
*   `addGateway`(`GatewayInterface` `$gateway`) → `void`
*   `getGateway`(`string` `$id`) → `Gateway|null`
*   `getStripeGateway`() → `StripeGateway|null`
*   `getList`() → `array` *(Note: Returns `Gateway[]`)*
*   `getListEnabled`() → `array` *(Note: Returns `Gateway[]`)*
*   `getListActive`() → `array` *(Note: Returns `Gateway[]`)*
*   `generateSubTabs`(`\MPHB\Admin\Tabs\SettingsTab` `$tab`) → `void`

## Class: MPHB\Payments\PaymentManager

*   `canBeCompleted`(`\MPHB\Entities\Payment` `$payment`) → `bool`
*   `canBeRefunded`(`\MPHB\Entities\Payment` `$payment`) → `bool`
*   `canBeFailed`(`\MPHB\Entities\Payment` `$payment`) → `bool`
*   `canBeOnHold`(`\MPHB\Entities\Payment` `$payment`) → `bool`
*   `canBeAbandoned`(`\MPHB\Entities\Payment` `$payment`) → `bool`
*   `canBeCancelled`(`\MPHB\Entities\Payment` `$payment`) → `bool`
*   `failPayment`(`\MPHB\Entities\Payment` `&$payment`, `string` `$log` = `''`, `bool` `$skipCheck` = `false`) → `bool`
*   `completePayment`(`\MPHB\Entities\Payment` `&$payment`, `string` `$log` = `''`, `bool` `$skipCheck` = `false`) → `bool`
*   `abandonPayment`(`\MPHB\Entities\Payment` `&$payment`, `string` `$log` = `''`, `bool` `$skipCheck` = `false`) → `bool`
*   `holdPayment`(`\MPHB\Entities\Payment` `&$payment`, `string` `$log` = `''`, `bool` `$skipCheck` = `false`) → `bool`
*   `refundPayment`(`\MPHB\Entities\Payment` `&$payment`, `string` `$log` = `''`, `bool` `$skipCheck` = `false`) → `bool`
*   `cancellPayment`(`\MPHB\Entities\Payment` `&$payment`, `string` `$log` = `''`, `bool` `$skipCheck` = `false`) → `bool`

## Class: MPHB\Shortcodes\CheckoutShortcode\StepCheckout

*   `__construct`() → `void`
*   `addInitActions`() → `void`
*   `addScriptDependencies`() → `void` (protected)
*   `setup`() → `void`
*   `getSingleRoomTypeOccupancyPresetsFromSearch`(`Entities\RoomType` `$roomType`) → `array` *(Note: Returns `int[]`)* (protected)
*   `parseBookingData`() → `bool` (protected)
*   `parseCustomerData`() → `void` (protected)
*   `render`() → `void`
*   `enqueueScripts`() → `void`
*   `redirectOnFailedLogin`() → `void`
*   `redirectAfterLogout`() → `void`

## Class: MPHBTOSS\TossGateway

*   `__construct`() → `void`
*   `registerOptionsFields`(`object` `&$subTab`) → `void`
*   `registerHooks`() → `void` (protected)
*   `initId`() → `string` (protected)
*   `initDefaultOptions`() → `array` (protected)
*   `setupProperties`() → `void` (protected)
*   `enqueueScripts`() → `void`
*   `processPayment`(`\MPHB\Entities\Booking` `$booking`, `\MPHB\Entities\Payment` `$payment`) → `array`
*   `handle_toss_callback`() → `void`
*   `isActive`() → `bool`

## Class: MPHBTOSS\TossAPI

*   `__construct`(`string` `$secretKey`, `bool` `$isDebug` = `false`) → `void`
*   `confirmPayment`(`string` `$paymentKey`, `string` `$tossOrderId`, `float` `$amount`) → `?object`
*   `cancelPayment`(`string` `$paymentKey`, `string` `$reason`, `?float` `$amount` = `null`) → `?object`
*   `getSecretKey`() → `string`
*   `setDebugMode`(`bool` `$isDebug`) → `void`

## Procedural Functions (Global Scope)

*   `mphb_get_template_part`(`string` `$slug`, `array` `$atts` = `array()`) → `void`
*   `mphb_load_template`(`string` `$template`, `array` `$templateArgs` = `array()`) → `void`
*   `mphb_current_time`(`string` `$type`, `int` `$gmt` = `0`) → `string`
*   `mphb_get_status_label`(`string` `$status`) → `string`
*   `mphb_set_cookie`(`string` `$name`, `string` `$value`, `int` `$expire` = `0`) → `void`
*   `mphb_get_cookie`(`string` `$name`) → `mixed|null`
*   `mphb_has_cookie`(`string` `$name`) → `bool`
*   `mphb_unset_cookie`(`string` `$name`) → `void`
*   `mphb_is_checkout_page`() → `bool`
*   `mphb_is_search_results_page`() → `bool`
*   `mphb_is_single_room_type_page`() → `bool`
*   `mphb_is_create_booking_page`() → `bool`
*   `mphb_get_thumbnail_width`() → `int`
*   `mphb_format_price`(`float` `$price`, `array` `$atts` = `array()`) → `string`
*   `mphb_format_percentage`(`float` `$price`, `array` `$atts` = `array()`) → `string`
*   `mphb_trim_zeros`(`mixed` `$price`) → `string`
*   `mphb_trim_decimal_zeros`(`mixed` `$price`) → `string`
*   `mphb_get_paged_query_var`() → `int`
*   `mphb_add_to_meta_query`(`array` `$queryPart`, `array|null` `$metaQuery`) → `array`
*   `mphb_meta_query_is_first_order_clause`(`array` `$query`) → `bool`
*   `mphb_clean`(`string|array` `$var`) → `string|array`
*   `mphb_hash_equals`(`string` `$knownString`, `string` `$userInput`) → `bool`
*   `mphb_strlen`(`string` `$s`) → `int`
*   `mphb_get_query_args`(`string` `$url`) → `array`
*   `mphb_wp_dropdown_pages`(`array` `$atts` = `array()`) → `string`
*   `mphb_set_time_limit`(`int` `$limit` = `0`) → `void`
*   `mphb_error_log`(`mixed` `$message`) → `void`
*   `mphb_current_domain`() → `string`
*   `mphb_generate_uuid4`() → `string`
*   `mphb_generate_uid`() → `string`
*   `mphb_get_edit_post_link_for_everyone`(`int|string` `$id`, `string` `$context` = `'display'`) → `string`
*   `mphb_get_rooms_select_list`(`int` `$typeId`) → `array`
*   `mphb_show_multiple_instances_notice`() → `void`
*   `mphb_upgrade_to_premium_message`(`string` `$wrapper` = `'span'`, `string` `$wrapperClass` = `'description'`) → `string`
*   `mphb_normilize_season_price`(`mixed` `$price`) → `array`
*   `mphb_is_reserved_term`(`string` `$termName`) → `bool`
*   `mphb_string_starts_with`(`string` `$haystack`, `string` `$needle`) → `bool`
*   `mphb_string_ends_with`(`string` `$haystack`, `string` `$needle`) → `bool`
*   `mphb_array_disjunction`(`array` `$a`, `array` `$b`) → `array`
*   `mphb_readable_post_statuses`() → `array`
*   `mphb_db_version`() → `string`
*   `mphb_db_version_at_least`(`string` `$requiredVersion`) → `bool`
*   `mphb_version_at_least`(`string` `$requiredVersion`) → `bool`
*   `mphb_wordpress_at_least`(`string` `$requiredVersion`) → `bool`
*   `mphb_fix_blocks_autop`() → `void`
*   `mphb_escape_json_unicodes`(`string` `$json`) → `string`
*   `mphb_strip_price_breakdown_json`(`string` `$json`) → `string`
*   `mphb_uploads_dir`() → `string`
*   `mphb_create_uploads_dir`() → `void`
*   `mphb_verify_nonce`(`mixed` `$action`, `string` `$nonceName` = `'mphb_nonce'`) → `bool`
*   `mphb_get_polyfill_for`(`string` `$function`) → `void`
*   `mphb_current_year`() → `int`
*   `mphb_days_in_month`(`int` `$month`, `int` `$year`) → `int`
*   `mphb_get_customer_fields`() → `array`
*   `mphb_get_default_customer_fields`() → `array`
*   `mphb_is_default_customer_field`(`string` `$fieldName`) → `bool`
*   `mphb_get_custom_customer_fields`() → `array`
*   `mphb_get_admin_checkout_customer_fields`() → `array`
*   `mphb_get_editing_post_id`() → `int`
*   `mphb_limit`(`int|float` `$value`, `int|float` `$min`, `int|float` `$max`) → `int|float`
*   `mphb_array_insert_after`(`array` `$array`, `int` `$position`, `array` `$insert`) → `array`
*   `mphb_array_insert_after_key`(`array` `$array`, `mixed` `$searchKey`, `array` `$insert`) → `array`
*   `mphb_array_usearch`(`array` `$haystack`, `callable` `$checkCallback`) → `mixed`
*   `mphb_prefix`(`string` `$str`, `string` `$separator` = `'_'`) → `string`
*   `mphb_unprefix`(`string` `$str`, `string` `$separator` = `'_'`) → `string`
*   `mphb_get_booking`(`int` `$bookingId`, `bool` `$force` = `false`) → `\MPHB\Entities\Booking|null`
*   `mphb_get_customer`(`int` `$bookingId`) → `\MPHB\Entities\Customer|null`
*   `mphb_get_room_type`(`int` `$roomTypeId`, `bool` `$force` = `false`) → `\MPHB\Entities\RoomType|null`
*   `mphb_get_season`(`int` `$seasonId`, `bool` `$force` = `false`) → `\MPHB\Entities\Season|null`
*   `mphb_is_base_request`(`string|null` `$postType` = `null`) → `bool`
*   `mphb_is_complete_booking`(`\MPHB\Entities\Booking` `$booking`) → `bool`
*   `mphb_is_pending_booking`(`\MPHB\Entities\Booking` `$booking`) → `bool`
*   `mphb_is_locking_booking`(`\MPHB\Entities\Booking` `$booking`) → `bool`
*   `mphb_is_failed_booking`(`\MPHB\Entities\Booking` `$booking`) → `bool`
*   `mphb_get_available_rooms`(`\DateTime` `$from`, `\DateTime` `$to`, `array` `$atts` = `array()`) → `array`
*   `mphb_posint`(`int|string` `$value`) → `int`
*   `mphb_get_min_adults`() → `int`
*   `mphb_get_min_children`() → `int`
*   `mphb_get_max_adults`() → `int`
*   `mphb_get_max_children`() → `int`
*   `mphb_array_flip_duplicates`(`array` `$array`, `bool` `$arraySingle` = `false`) → `array`
*   `mphb_get_room_type_base_price`(`\MPHB\Entities\RoomType|int|null` `$roomType` = `null`, `\DateTime|null` `$startDate` = `null`, `\DateTime|null` `$endDate` = `null`) → `float`
*   `mphb_get_room_type_period_price`(`\DateTime` `$startDate`, `\DateTime` `$endDate`, `\MPHB\Entities\RoomType|int|null` `$roomType` = `null`, `array` `$args` = `array()`) → `float`
*   `mphb_get_room_type_ids`(`string` `$language` = `'any'`, `array` `$atts` = `array()`) → `array` *(Note: Returns `int[]`)*
*   `mphb_today`(`string` `$modifier` = `''`) → `\DateTime`
*   `mphb_modify_buffer_period`(`\DateTime` `$startDate`, `\DateTime` `$endDate`, `int` `$bufferDays` = `0`) → `array` *(Note: Deprecated. Returns `\DateTime[]`)*
*   `mphb_modify_booking_buffer_period`(`\MPHB\Entities\Booking` `$booking`, `int` `$bufferDays`, `bool` `$extendDates` = `false`) → `array` *(Note: Deprecated. Returns `\DateTime[]`)*
*   `mphb_is_rooms_free_query_atts_with_buffer`(`array` `$atts`) → `array`
*   `mphb_help_tip`(`string` `$tip`, `bool` `$allow_html` = `false`) → `string`
*   `mphb_create_url`(`string` `$endpoint`, `string` `$value` = `''`, `string` `$permalink` = `''`) → `string`
*   `mphb_parse_queue_room_id`(`string` `$queueItem`) → `int`


### 클래스: MPHB\Payments\Gateways\Gateway (abstract)

**Public 함수:**

*   `__construct()`
*   `isShowOptions() : bool`
*   `getId() : string`
*   `getAdminTitle() : string`
*   `getAdminDescription() : string`
*   `getTitle() : string`
*   `getInstructions() : string`
*   `isEnabled() : bool`
*   `isActive() : bool`
*   `getDescription() : string`
*   `setupPaymentFields() : void`
*   `initPaymentFields() : array`
*   `preRegister(array $suspendPayments) : void`
*   `register(\MPHB\Payments\Gateways\GatewayManager $gatewayManager) : void`
*   `processPayment(\MPHB\Entities\Booking $booking, \MPHB\Entities\Payment $payment) : void` (abstract)
*   `getMode() : string`
*   `parsePaymentFields(array $input, array &$errors) : bool`
*   `renderPaymentFields(\MPHB\Entities\Booking $booking) : void`
*   `storePaymentFields(\MPHB\Entities\Payment $payment) : bool`
*   `registerOptionsFields(\MPHB\Admin\Tabs\SettingsSubTab &$subTab) : void`
*   `isSandbox() : bool`
*   `generateItemName(\MPHB\Entities\Booking $booking) : string`
*   `getCheckoutData(\MPHB\Entities\Booking $booking) : array`
*   `hasPaymentFields() : bool`
*   `hasVisiblePaymentFields() : bool`

**Protected 함수:**

*   `initDefaultOptions() : array`
*   `setupProperties() : void`
*   `getOption(string $optionName) : mixed`
*   `getDefaultOption(string $optionName) : mixed`
*   `initId() : string` (abstract)
*   `paymentCompleted(\MPHB\Entities\Payment $payment) : bool`
*   `paymentFailed(\MPHB\Entities\Payment $payment) : bool`
*   `paymentOnHold(\MPHB\Entities\Payment $payment) : bool`
*   `paymentRefunded(\MPHB\Entities\Payment $payment) : bool`

### 클래스: MPHB\Repositories\BookingRepository

**Public 함수:**

*   `findById(int $id, bool $force = false) : \MPHB\Entities\Booking`
*   `findAllByCustomer(int $customerId, array $atts = array(), bool $all = true) : array`
*   `findByPayment(\MPHB\Entities\Payment|int $payment, bool $force = false) : ?\MPHB\Entities\Booking`
*   `findByCheckoutId(string $checkoutId) : ?\MPHB\Entities\Booking`
*   `findAll(array $atts = array()) : array`  (리턴 타입: `\MPHB\Entities\Booking[]`)
*   `findAllInPeriod(\DateTime|string $dateFrom, \DateTime|string $dateTo, array $atts = array()) : array` (리턴 타입: `\MPHB\Entities\Booking[]`)
*   `findAllByCalendar(string $syncId, string $fields = 'ids') : array` (리턴 타입: `int[]|\MPHB\Entities\Booking[]`)
*   `findRandom(array $args = array()) : ?\MPHB\Entities\Booking`
*   `mapPostToEntity(\WP_Post|int $post) : \MPHB\Entities\Booking`
*   `mapEntityToPostData(\MPHB\Entities\Booking $entity) : \MPHB\Entities\WPPostData`
*   `save(\MPHB\Entities\Booking &$entity) : bool`
*   `updateReservedRooms(int $bookingId) : void`
*   `getImportedCount() : int`

**Private 함수:**

*   `retrieveBookingAtts(int $postId) : array|false`



### 클래스: MPHB\Repositories\PaymentRepository

**Public 함수:**

*   `mapPostToEntity(\WP_Post|int $post) : \MPHB\Entities\Payment`
*   `mapEntityToPostData(\MPHB\Entities\Payment $entity) : \MPHB\Entities\WPPostData`
*   `findById(int $id, bool $force = false) : \MPHB\Entities\Payment`
*   `findAll(array $atts = array()) : array` (리턴 타입: `\MPHB\Entities\Payment[]`)
*   `findByTransactionId(string $transactionId) : ?\MPHB\Entities\Payment`

### 클래스: MPHB\Settings\PageSettings

**Public 함수:**

*   `getCheckoutPageId() : int`
*   `getCheckoutPageUrl() : string|bool`
*   `getBookingConfirmedPageId() : int`
*   `getBookingConfirmedPageUrl() : string|false`
*   `getSearchResultsPageId() : int`
*   `getSearchResultsPageUrl() : string|bool`
*   `getUserCancelRedirectPageId() : int|false`
*   `getUserCancelRedirectPageUrl() : string|false`
*   `getPaymentSuccessPageId() : int` (@deprecated since 3.7)
*   `getPaymentSuccessPageUrl(\MPHB\Entities\Payment $payment = null, array $additionalArgs = array()) : string|false` (@deprecated since 3.7)
*   `getReservationReceivedPageId() : int`
*   `getReservationReceivedPageUrl(\MPHB\Entities\Payment $payment = null, array $additionalArgs = array()) : string|false`
*   `getPaymentFailedPageId() : int`
*   `getPaymentFailedPageUrl(\MPHB\Entities\Payment $payment = null, array $additionalArgs = array()) : string|false`
*   `getTermsAndConditionsPageId() : int`
*   `getOpenTermsAndConditionsInNewWindow() : bool`
*   `getMyAccountPageId() : int`
*   `setMyAccountPageId(int $id) : bool`
*   `getUrl(string|int $id) : string|false`
*   `setCheckoutPage(string $id) : bool`
*   `setSearchResultsPage(string $id) : bool`
*   `setBookingConfirmPage(string $id) : bool`
*   `setUserCancelRedirectPage(string $id) : bool`
*   `setPaymentSuccessPage(string $id) : bool`
*   `setPaymentFailedPage(string $id) : bool`
*   `setBookingConfirmCancellationPage(string $id) : bool`
*   `getBookingConfirmCancellationPage() : int`

**Private 함수:**

*   `getPageId(string $name) : int`

### 클래스: MPHB\Settings\PaymentSettings

**Public 함수:**

*   `getAmountType() : string`
*   `getDefaultAmountType() : string`
*   `getDepositType() : string`
*   `getDefaultDepositType() : string`
*   `getDepositAmount() : float`
*   `getDefaultDepositAmount() : float`
*   `getDepositTimeFrame() : int|false`
*   `getDefaultGateway() : string`
*   `getPendingTime() : int`
*   `getDefaultPendingTime() : int`
*   `isForceCheckoutSSL() : bool`
*   `getDefaultForceCheckoutSSL() : bool`


### 클래스: MPHB\Shortcodes\CheckoutShortcode\StepBooking

**Public 함수:**

*   `setup() : void`
*   `render() : void`

**Protected 함수:**

*   `parseCheckoutId() : bool`
*   `parseUnfinishedBookingData() : bool`
*   `parseBookingData() : bool`
*   `parseCustomerData() : bool`
*   `parsePaymentData() : bool`
*   `parseGatewayId() : bool`
*   `parsePaymentMethodFields() : bool`
*   `createPayment(\MPHB\Entities\Booking $booking) : ?\MPHB\Entities\Payment`
*   `cleanUnfinished() : void`
*   `createCustomer() : int|\WP_Error`


### 클래스: MPHB\Shortcodes\CheckoutShortcode\StepCheckout

**Public 함수:**

*   `__construct()`
*   `addInitActions() : void`
*   `addScriptDependencies() : void`
*   `setup() : void`
*   `render() : void`
*   `enqueueScripts() : void`
*   `redirectOnFailedLogin() : void`
*   `redirectAfterLogout() : void`

**Protected 함수:**

*   `getSingleRoomTypeOccupancyPresetsFromSearch(\MPHB\Entities\RoomType $roomType) : array` (리턴 타입: `int[]`)
*   `parseBookingData() : bool`
*   `parseCustomerData() : void`
