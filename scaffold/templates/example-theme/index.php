<?php
/**
 * The main template file.
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * E.g., it puts together the home page when no home.php file exists.
 *
 * @link https://codex.wordpress.org/Template_Hierarchy
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="profile" href="http://gmpg.org/xfn/11">

<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#content">Skip to content</a>

	<header id="masthead" role="banner">
		<div class="site-branding">
			<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></h1>
		</div><!-- .site-branding -->
	</header><!-- #masthead -->

	<div id="content">

	<div id="primary">
		<main id="main" class="site-main" role="main">

	<?php
	if ( have_posts() ) :
		while ( have_posts() ) :
			the_post();
			?>
			<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
				<header class="entry-header">
			<?php
			if ( is_single() ) :
				the_title( '<h1 class="entry-title">', '</h1>' );
				else :
					the_title( '<h2 class="entry-title"><a href="' . get_permalink() . '" rel="bookmark">', '</a></h2>' );
				endif;
				if ( 'post' === get_post_type() ) :
					?>
					<div class="entry-meta">
						<p>Posted on <?php the_date(); ?> by <?php the_author(); ?>.</p>
					</div><!-- .entry-meta -->
					<?php
				endif;
				?>
				</header><!-- .entry-header -->

				<div class="entry-content">
					<?php the_content(); ?>
				</div><!-- .entry-content -->
			</article><!-- #post-## -->
			<?php
	endwhile;
		the_posts_navigation();
		else :
			?>
			<div class="page-content">
				<h2>Nothing Found</h2>
				<p>It seems we can't find what you're looking for. Perhaps searching can help:</p>
			<?php get_search_form(); ?>
			</div>
		<?php endif; ?>

		</main><!-- #main -->
	</div><!-- #primary -->

	</div><!-- #content -->

	<footer id="colophon" role="contentinfo">
		<div class="site-info">
			<a href="https://wordpress.org/">Proudly powered by WordPress</a>
		</div><!-- .site-info -->
	</footer><!-- #colophon -->
</div><!-- #page -->

<?php wp_footer(); ?>

</body>
</html>
