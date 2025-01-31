<?php
namespace Automattic\WooCommerce\Blocks\Domain;

use Automattic\WooCommerce\Blocks\Assets\Api as AssetApi;
use Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry;
use Automattic\WooCommerce\Blocks\AssetsController;
use Automattic\WooCommerce\Blocks\BlockPatterns;
use Automattic\WooCommerce\Blocks\BlockTemplatesController;
use Automattic\WooCommerce\Blocks\BlockTypesController;
use Automattic\WooCommerce\Blocks\QueryFilters;
use Automattic\WooCommerce\Blocks\Domain\Services\CreateAccount;
use Automattic\WooCommerce\Blocks\Domain\Services\Notices;
use Automattic\WooCommerce\Blocks\Domain\Services\DraftOrders;
use Automattic\WooCommerce\Blocks\Domain\Services\FeatureGating;
use Automattic\WooCommerce\Blocks\Domain\Services\GoogleAnalytics;
use Automattic\WooCommerce\Blocks\Domain\Services\Hydration;
use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\InboxNotifications;
use Automattic\WooCommerce\Blocks\Installer;
use Automattic\WooCommerce\Blocks\Migration;
use Automattic\WooCommerce\Blocks\Payments\Api as PaymentsApi;
use Automattic\WooCommerce\Blocks\Payments\Integrations\BankTransfer;
use Automattic\WooCommerce\Blocks\Payments\Integrations\CashOnDelivery;
use Automattic\WooCommerce\Blocks\Payments\Integrations\Cheque;
use Automattic\WooCommerce\Blocks\Payments\Integrations\PayPal;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use Automattic\WooCommerce\Blocks\Registry\Container;
use Automattic\WooCommerce\Blocks\Templates\CartTemplate;
use Automattic\WooCommerce\Blocks\Templates\CheckoutHeaderTemplate;
use Automattic\WooCommerce\Blocks\Templates\CheckoutTemplate;
use Automattic\WooCommerce\Blocks\Templates\ClassicTemplatesCompatibility;
use Automattic\WooCommerce\Blocks\Templates\OrderConfirmationTemplate;
use Automattic\WooCommerce\Blocks\Templates\ProductAttributeTemplate;
use Automattic\WooCommerce\Blocks\Templates\ProductSearchResultsTemplate;
use Automattic\WooCommerce\StoreApi\RoutesController;
use Automattic\WooCommerce\StoreApi\SchemaController;
use Automattic\WooCommerce\StoreApi\StoreApi;
use Automattic\WooCommerce\Blocks\Shipping\ShippingController;
use Automattic\WooCommerce\Blocks\Templates\SingleProductTemplateCompatibility;
use Automattic\WooCommerce\Blocks\Templates\ArchiveProductTemplatesCompatibility;
use Automattic\WooCommerce\Blocks\Domain\Services\OnboardingTasks\TasksController;

/**
 * Takes care of bootstrapping the plugin.
 *
 * @since 2.5.0
 */
class Bootstrap {

	/**
	 * Holds the Dependency Injection Container
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Holds the Package instance
	 *
	 * @var Package
	 */
	private $package;


	/**
	 * Holds the Migration instance
	 *
	 * @var Migration
	 */
	private $migration;

	/**
	 * Constructor
	 *
	 * @param Container $container  The Dependency Injection Container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
		$this->package   = $container->get( Package::class );
		$this->migration = $container->get( Migration::class );

		$this->init();
		/**
		 * Fires when the woocommerce blocks are loaded and ready to use.
		 *
		 * This hook is intended to be used as a safe event hook for when the plugin
		 * has been loaded, and all dependency requirements have been met.
		 *
		 * To ensure blocks are initialized, you must use the `woocommerce_blocks_loaded`
		 * hook instead of the `plugins_loaded` hook. This is because the functions
		 * hooked into plugins_loaded on the same priority load in an inconsistent and unpredictable manner.
		 *
		 * @since 2.5.0
		 */
		do_action( 'woocommerce_blocks_loaded' );
	}

	/**
	 * Init the package - load the blocks library and define constants.
	 */
	protected function init() {
		$this->register_dependencies();
		$this->register_payment_methods();
		$this->load_interactivity_api();

		// This is just a temporary solution to make sure the migrations are run. We have to refactor this. More details: https://github.com/woocommerce/woocommerce-blocks/issues/10196.
		if ( $this->package->get_version() !== $this->package->get_version_stored_on_db() ) {
			$this->migration->run_migrations();
			$this->package->set_version_stored_on_db();
		}

		add_action(
			'admin_init',
			function() {
				// Delete this notification because the blocks are included in WC Core now. This will handle any sites
				// with lingering notices.
				InboxNotifications::delete_surface_cart_checkout_blocks_notification();
			},
			10,
			0
		);

		$is_rest = wc()->is_rest_api_request();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$is_store_api_request = $is_rest && ! empty( $_SERVER['REQUEST_URI'] ) && ( false !== strpos( $_SERVER['REQUEST_URI'], trailingslashit( rest_get_url_prefix() ) . 'wc/store/' ) );

		// Load and init assets.
		$this->container->get( StoreApi::class )->init();
		$this->container->get( PaymentsApi::class )->init();
		$this->container->get( DraftOrders::class )->init();
		$this->container->get( CreateAccount::class )->init();
		$this->container->get( ShippingController::class )->init();
		$this->container->get( TasksController::class )->init();
		$this->container->get( CheckoutFields::class );

		// Load assets in admin and on the frontend.
		if ( ! $is_rest ) {
			$this->add_build_notice();
			$this->container->get( AssetDataRegistry::class );
			$this->container->get( AssetsController::class );
			$this->container->get( Installer::class )->init();
			$this->container->get( GoogleAnalytics::class )->init();
			$this->container->get( CheckoutFields::class )->init();
		}

		// Load assets unless this is a request specifically for the store API.
		if ( ! $is_store_api_request ) {
			// Template related functionality. These won't be loaded for store API requests, but may be loaded for
			// regular rest requests to maintain compatibility with the store editor.
			$this->container->get( BlockPatterns::class );
			$this->container->get( BlockTypesController::class );
			$this->container->get( BlockTemplatesController::class );
			$this->container->get( ProductSearchResultsTemplate::class );
			$this->container->get( ProductAttributeTemplate::class );
			$this->container->get( CartTemplate::class );
			$this->container->get( CheckoutTemplate::class );
			$this->container->get( CheckoutHeaderTemplate::class );
			$this->container->get( OrderConfirmationTemplate::class );
			$this->container->get( ClassicTemplatesCompatibility::class );
			$this->container->get( ArchiveProductTemplatesCompatibility::class )->init();
			$this->container->get( SingleProductTemplateCompatibility::class )->init();
			$this->container->get( Notices::class )->init();
		}

		$this->container->get( QueryFilters::class )->init();
	}

	/**
	 * See if files have been built or not.
	 *
	 * @return bool
	 */
	protected function is_built() {
		return file_exists(
			$this->package->get_path( 'assets/client/blocks/featured-product.js' )
		);
	}

	/**
	 * Add a notice stating that the build has not been done yet.
	 */
	protected function add_build_notice() {
		if ( $this->is_built() ) {
			return;
		}
		add_action(
			'admin_notices',
			function() {
				echo '<div class="error"><p>';
				printf(
					/* translators: %1$s is the install command, %2$s is the build command, %3$s is the watch command. */
					esc_html__( 'WooCommerce Blocks development mode requires files to be built. From the plugin directory, run %1$s to install dependencies, %2$s to build the files or %3$s to build the files and watch for changes.', 'woocommerce' ),
					'<code>npm install</code>',
					'<code>npm run build</code>',
					'<code>npm start</code>'
				);
				echo '</p></div>';
			}
		);
	}

	/**
	 * Load and set up the Interactivity API if enabled.
	 */
	protected function load_interactivity_api() {
			require_once __DIR__ . '/../Interactivity/load.php';
	}

	/**
	 * Register core dependencies with the container.
	 */
	protected function register_dependencies() {
		$this->container->register(
			FeatureGating::class,
			function () {
				return new FeatureGating();
			}
		);
		$this->container->register(
			AssetApi::class,
			function ( Container $container ) {
				return new AssetApi( $container->get( Package::class ) );
			}
		);
		$this->container->register(
			AssetDataRegistry::class,
			function( Container $container ) {
				return new AssetDataRegistry( $container->get( AssetApi::class ) );
			}
		);
		$this->container->register(
			AssetsController::class,
			function( Container $container ) {
				return new AssetsController( $container->get( AssetApi::class ) );
			}
		);
		$this->container->register(
			PaymentMethodRegistry::class,
			function() {
				return new PaymentMethodRegistry();
			}
		);
		$this->container->register(
			Installer::class,
			function () {
				return new Installer();
			}
		);
		$this->container->register(
			BlockTypesController::class,
			function ( Container $container ) {
				$asset_api           = $container->get( AssetApi::class );
				$asset_data_registry = $container->get( AssetDataRegistry::class );
				return new BlockTypesController( $asset_api, $asset_data_registry );
			}
		);
		$this->container->register(
			BlockTemplatesController::class,
			function ( Container $container ) {
				return new BlockTemplatesController( $container->get( Package::class ) );
			}
		);
		$this->container->register(
			ProductSearchResultsTemplate::class,
			function () {
				return new ProductSearchResultsTemplate();
			}
		);
		$this->container->register(
			ProductAttributeTemplate::class,
			function () {
				return new ProductAttributeTemplate();
			}
		);
		$this->container->register(
			CartTemplate::class,
			function () {
				return new CartTemplate();
			}
		);
		$this->container->register(
			CheckoutTemplate::class,
			function () {
				return new CheckoutTemplate();
			}
		);
		$this->container->register(
			CheckoutHeaderTemplate::class,
			function () {
				return new CheckoutHeaderTemplate();
			}
		);
		$this->container->register(
			OrderConfirmationTemplate::class,
			function () {
				return new OrderConfirmationTemplate();
			}
		);
		$this->container->register(
			ClassicTemplatesCompatibility::class,
			function ( Container $container ) {
				$asset_data_registry = $container->get( AssetDataRegistry::class );
				return new ClassicTemplatesCompatibility( $asset_data_registry );
			}
		);
		$this->container->register(
			ArchiveProductTemplatesCompatibility::class,
			function () {
				return new ArchiveProductTemplatesCompatibility();
			}
		);

		$this->container->register(
			SingleProductTemplateCompatibility::class,
			function () {
				return new SingleProductTemplateCompatibility();
			}
		);
		$this->container->register(
			DraftOrders::class,
			function( Container $container ) {
				return new DraftOrders( $container->get( Package::class ) );
			}
		);
		$this->container->register(
			CreateAccount::class,
			function( Container $container ) {
				return new CreateAccount( $container->get( Package::class ) );
			}
		);
		$this->container->register(
			GoogleAnalytics::class,
			function( Container $container ) {
				$asset_api = $container->get( AssetApi::class );
				return new GoogleAnalytics( $asset_api );
			}
		);
		$this->container->register(
			Notices::class,
			function( Container $container ) {
				return new Notices( $container->get( Package::class ) );
			}
		);
		$this->container->register(
			Hydration::class,
			function( Container $container ) {
				return new Hydration( $container->get( AssetDataRegistry::class ) );
			}
		);
		$this->container->register(
			CheckoutFields::class,
			function( Container $container ) {
				return new CheckoutFields( $container->get( AssetDataRegistry::class ) );
			}
		);
		$this->container->register(
			PaymentsApi::class,
			function ( Container $container ) {
				$payment_method_registry = $container->get( PaymentMethodRegistry::class );
				$asset_data_registry     = $container->get( AssetDataRegistry::class );
				return new PaymentsApi( $payment_method_registry, $asset_data_registry );
			}
		);
		$this->container->register(
			StoreApi::class,
			function () {
				return new StoreApi();
			}
		);
		// Maintains backwards compatibility with previous Store API namespace.
		$this->container->register(
			'Automattic\WooCommerce\Blocks\StoreApi\Formatters',
			function( Container $container ) {
				$this->deprecated_dependency( 'Automattic\WooCommerce\Blocks\StoreApi\Formatters', '7.2.0', 'Automattic\WooCommerce\StoreApi\Formatters', '7.4.0' );
				return $container->get( StoreApi::class )->container()->get( \Automattic\WooCommerce\StoreApi\Formatters::class );
			}
		);
		$this->container->register(
			'Automattic\WooCommerce\Blocks\Domain\Services\ExtendRestApi',
			function( Container $container ) {
				$this->deprecated_dependency( 'Automattic\WooCommerce\Blocks\Domain\Services\ExtendRestApi', '7.2.0', 'Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema', '7.4.0' );
				return $container->get( StoreApi::class )->container()->get( \Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema::class );
			}
		);
		$this->container->register(
			'Automattic\WooCommerce\Blocks\StoreApi\SchemaController',
			function( Container $container ) {
				$this->deprecated_dependency( 'Automattic\WooCommerce\Blocks\StoreApi\SchemaController', '7.2.0', 'Automattic\WooCommerce\StoreApi\SchemaController', '7.4.0' );
				return $container->get( StoreApi::class )->container()->get( SchemaController::class );
			}
		);
		$this->container->register(
			'Automattic\WooCommerce\Blocks\StoreApi\RoutesController',
			function( Container $container ) {
				$this->deprecated_dependency( 'Automattic\WooCommerce\Blocks\StoreApi\RoutesController', '7.2.0', 'Automattic\WooCommerce\StoreApi\RoutesController', '7.4.0' );
				return $container->get( StoreApi::class )->container()->get( RoutesController::class );
			}
		);
		$this->container->register(
			BlockPatterns::class,
			function () {
				return new BlockPatterns( $this->package );
			}
		);
		$this->container->register(
			ShippingController::class,
			function ( $container ) {
				$asset_api           = $container->get( AssetApi::class );
				$asset_data_registry = $container->get( AssetDataRegistry::class );
				return new ShippingController( $asset_api, $asset_data_registry );
			}
		);
		$this->container->register(
			TasksController::class,
			function() {
				return new TasksController();
			}
		);
		$this->container->register(
			QueryFilters::class,
			function() {
				return new QueryFilters();
			}
		);
	}

	/**
	 * Throws a deprecation notice for a dependency without breaking requests.
	 *
	 * @param string $function Class or function being deprecated.
	 * @param string $version Version in which it was deprecated.
	 * @param string $replacement Replacement class or function, if applicable.
	 * @param string $trigger_error_version Optional version to start surfacing this as a PHP error rather than a log. Defaults to $version.
	 */
	protected function deprecated_dependency( $function, $version, $replacement = '', $trigger_error_version = '' ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$trigger_error_version = $trigger_error_version ? $trigger_error_version : $version;
		$error_message         = $replacement ? sprintf(
			'%1$s is <strong>deprecated</strong> since version %2$s! Use %3$s instead.',
			$function,
			$version,
			$replacement
		) : sprintf(
			'%1$s is <strong>deprecated</strong> since version %2$s with no alternative available.',
			$function,
			$version
		);
		/**
		 * Fires when a deprecated function is called.
		 *
		 * @since 7.3.0
		 */
		do_action( 'deprecated_function_run', $function, $replacement, $version );

		$log_error = false;

		// If headers have not been sent yet, log to avoid breaking the request.
		if ( ! headers_sent() ) {
			$log_error = true;
		}

		// If the $trigger_error_version was not yet reached, only log the error.
		if ( version_compare( $this->package->get_version(), $trigger_error_version, '<' ) ) {
			$log_error = true;
		}

		/**
		 * Filters whether to trigger an error for deprecated functions. (Same as WP core)
		 *
		 * @since 7.3.0
		 *
		 * @param bool $trigger Whether to trigger the error for deprecated functions. Default true.
		 */
		if ( ! apply_filters( 'deprecated_function_trigger_error', true ) ) {
			$log_error = true;
		}

		if ( $log_error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $error_message );
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error( $error_message, E_USER_DEPRECATED );
		}
	}

	/**
	 * Register payment method integrations with the container.
	 */
	protected function register_payment_methods() {
		$this->container->register(
			Cheque::class,
			function( Container $container ) {
				$asset_api = $container->get( AssetApi::class );
				return new Cheque( $asset_api );
			}
		);
		$this->container->register(
			PayPal::class,
			function( Container $container ) {
				$asset_api = $container->get( AssetApi::class );
				return new PayPal( $asset_api );
			}
		);
		$this->container->register(
			BankTransfer::class,
			function( Container $container ) {
				$asset_api = $container->get( AssetApi::class );
				return new BankTransfer( $asset_api );
			}
		);
		$this->container->register(
			CashOnDelivery::class,
			function( Container $container ) {
				$asset_api = $container->get( AssetApi::class );
				return new CashOnDelivery( $asset_api );
			}
		);
	}
}
