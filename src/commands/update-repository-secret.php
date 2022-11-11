<?php
/**
 *
 * phpcs:disable WordPress.Files.FileName.InvalidClassFileName
 */

namespace Team51\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Team51\Helper\API_Helper;

/**
 * class Update_Repository_Secret
 */
class Update_Repository_Secret extends Command {

	/**
	 * @var string|null The default command name.
	 */
	protected static $defaultName = 'update-repository-secret'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase

	/**
	 * @var string $repo_name Repository name.
	 */
	protected $repo_name = '';

	/**
	 * @var string $secret_name Secret name.
	 */
	protected $secret_name = '';

	/**
	 * @var Api_Helper|null API Helper instance.
	 */
	protected $api_helper = null;

	/**
	 * @var OutputInterface $output Output instance.
	 */
	protected $output;


	public function __construct() {
		parent::__construct();

		$this->api_helper = new API_Helper();
	}

	protected function configure() {
		$this
			->setDescription( 'Updates GitHub repository secret on github.com in the organization specified with GITHUB_API_OWNER. and project name' )
			->setHelp( 'This command allows you to update Github repository secret or create one if it is missing.' )
			->addOption( 'repo-slug', null, InputOption::VALUE_REQUIRED, 'Repository name in slug form (e.g. client-name)?' )
			->addOption( 'secret-name', null, InputOption::VALUE_REQUIRED, 'Secret name in all caps (e.g. GH_BOT_TOKEN)?' );
	}

	/**
	 * Executes the current command.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return void
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$template_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.tpl.json';
		$config_file = TEAM51_CLI_ROOT_DIR . '/secrets/config.json';
		\exec( \sprintf( 'op inject -i %1$s -o %2$s', $template_file, $config_file ) );

		$config = json_decode( file_get_contents( $config_file ) );
		\unlink( $config_file );

		$this->output   = $output;
		$success_repo   = $this->verify_input_repo_name( $input );
		$success_secret = $this->verify_input_secret_name( $input );

		if ( false === $success_repo || false === $success_secret ) {
			return 1;
		}

		$this->repo_name       = $input->getOption( 'repo-slug' );
		$this->secret_name     = $input->getOption( 'secret-name' );
		$success_secret_exists = $this->verify_secret_exists( $this->secret_name, $config );

		if ( false === $success_secret_exists ) {
			return 1;
		}

		$this->verify_repo_on_github();

		$repo_key = $this->get_repo_public_key();
		$this->verify_repo_key( $repo_key );

		$new_secret = $this->get_secret_key( $this->secret_name, $config );

		if ( empty( $new_secret ) ) {
			return 1;
		}

		$repo_public_key_id = $repo_key['key_id'];
		$repo_public_key    = base64_decode( $repo_key['key'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		$this->verify_public_key( $repo_public_key );

		$sealbox = $this->seal_secret( $new_secret, $repo_public_key );

		$this->update_repo_secret( $sealbox, $repo_public_key_id, $this->secret_name );

		return 0;
	}

	/**
	 * Is passed string valid repository name in org/user.
	 *
	 * @return bool
	 */
	private function is_valid_repo(): bool {
		$repository_exists = $this->api_helper->call_github_api(
			sprintf( 'repos/%s/%s', GITHUB_API_OWNER, $this->repo_name ),
			'',
			'GET'
		);

		return ! ( true === empty( $repository_exists->id ) );
	}

	/**
	 * Get repo public key.
	 *
	 * @return array
	 */
	private function get_repo_public_key(): array {
		$key_response = $this->api_helper->call_github_api(
			sprintf( 'repos/%s/%s/actions/secrets/public-key', GITHUB_API_OWNER, $this->repo_name ),
			array(),
			'GET'
		);

		return array(
			'key_id' => $key_response->key_id ?? null,
			'key'    => $key_response->key ?? null,
		);
	}

	/**
	 * Update GitHub repo secret.
	 *
	 * @param string $sealbox Sealed secret string.
	 * @param int $key_id Github KEY ID.
	 * @param string $secret_name Secret name to be used.
	 */
	private function update_repo_secret( string $sealbox, int $key_id, string $secret_name ) {
		$update_response = $this->api_helper->call_github_api(
			sprintf( 'repos/%s/%s/actions/secrets/%s', GITHUB_API_OWNER, $this->repo_name, $secret_name ),
			array(
				'encrypted_value' => $sealbox,
				'key_id'          => (string) $key_id,
			),
			'PUT'
		);
	}

	/**
	 * Generate base64 encoded sealed box of passed secret.
	 *
	 * @throws \SodiumException
	 */
	private function seal_secret( string $secret_string, string $public_key ): string {
		return base64_encode( sodium_crypto_box_seal( $secret_string, $public_key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function verify_input_repo_name( InputInterface $input ) {
		if ( true === empty( $input->getOption( 'repo-slug' ) ) ) {
			$this->output->writeln( '<error>You must pass a repository slug with --repo-slug.</error>' );
			return false;
		}
	}

	private function verify_input_secret_name( InputInterface $input ) {
		if ( true === empty( $input->getOption( 'secret-name' ) ) ) {
			$this->output->writeln( '<error>You must pass a secret name with --secret-name.</error>' );
			return false;
		}
	}

	private function verify_secret_exists( string $secret_name, $config ) {
		$secret_boom = \array_map( 'strtolower', \explode( '_', $secret_name, 2 ) );
		if ( 'GH_BOT_TOKEN' !== $secret_name && empty( $config->{$secret_boom[0]}->{$secret_boom[1]} ) ) { // Legacy is a beach!
			$this->output->writeln( '<error>Secret does not exist in config.json file.</error>' );
			return false;
		}
	}

	private function get_secret_key( string $secret_name, $config ) {
		if ( 'GH_BOT_TOKEN' === $secret_name && empty( $config->GH_BOT_TOKEN ) ) { // Legacy is a beach!
			$secret_name = 'GITHUB_API_BOT_SECRETS_TOKEN';
			$this->verify_secret_exists( $secret_name, $config );
		}
		$secret_boom = \array_map( 'strtolower', \explode( '_', $secret_name, 2 ) );
		if ( ! empty( $config->{$secret_boom[0]}->{$secret_boom[1]} ) ) {
			$secret = $config->{$secret_boom[0]}->{$secret_boom[1]};
		} else {
			$this->output->writeln( '<error>Secret could not be retrieved. Aborting!</error>' );
		}
		return $secret;
	}

	/**
	 * @param string|bool $public_key
	 *
	 * @return void
	 */
	private function verify_public_key( $public_key ): void {
		if ( false === $public_key ) {
			$this->output->writeln( "<error>Repository key for {$this->repo_name} is empty.</error>" );
			$this->exit();
		}
	}

	/**
	 * Verify that repo name exists on GitHub.
	 */
	private function verify_repo_on_github(): void {
		// Verify repo we're trying to update secret exist.
		if ( false === $this->is_valid_repo() ) {
			$this->output->writeln( "<error>Repository {$this->repo_name} doesn't exist in GitHub org. Please choose a different repository name. Aborting!</error>" );
			$this->exit();
		}
	}

	/**
	 * Verify that both key_id and key elements exist in repo_key.
	 *
	 * @param array $repo_key
	 *
	 * @return void
	 */
	private function verify_repo_key( array $repo_key ): void {
		if ( true === empty( $repo_key['key_id'] ) || true === empty( $repo_key['key'] ) ) {
			$this->output->writeln( "<error>Repository key for {$this->repo_name} is empty. Aborting!</error>" );
			$this->exit();
		}
	}

	/**
	 * Exit/break command execution.
	 *
	 * @param int $code
	 */
	private function exit( int $code = 1 ): void {
		exit( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
