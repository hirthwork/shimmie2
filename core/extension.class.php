<?php
/**
 * \page eande Events and Extensions
 *
 * An event is a little blob of data saying "something happened", possibly
 * "something happened, here's the specific data". Events are sent with the
 * send_event() function. Since events can store data, they can be used to
 * return data to the extension which sent them, for example:
 *
 * \code
 * $tfe = new TextFormattingEvent($original_text);
 * send_event($tfe);
 * $formatted_text = $tfe->formatted;
 * \endcode
 *
 * An extension is something which is capable of reacting to events.
 *
 *
 * \page hello The Hello World Extension
 *
 * \code
 * // ext/hello/main.php
 * public class HelloEvent extends Event {
 *     public function __construct($username) {
 *         $this->username = $username;
 *     }
 * }
 *
 * public class Hello extends Extension {
 *     public function onPageRequest(PageRequestEvent $event) {   // Every time a page request is sent
 *         global $user;                                          // Look at the global "currently logged in user" object
 *         send_event(new HelloEvent($user->name));               // Broadcast a signal saying hello to that user
 *     }
 *     public function onHello(HelloEvent $event) {               // When the "Hello" signal is recieved
 *         $this->theme->display_hello($event->username);         // Display a message on the web page
 *     }
 * }
 *
 * // ext/hello/theme.php
 * public class HelloTheme extends Themelet {
 *     public function display_hello($username) {
 *         global $page;
 *         $h_user = html_escape($username);                     // Escape the data before adding it to the page
 *         $block = new Block("Hello!", "Hello there $h_user");  // HTML-safe variables start with "h_"
 *         $page->add_block($block);                             // Add the block to the page
 *     }
 * }
 *
 * // ext/hello/test.php
 * public class HelloTest extends SCorePHPUnitTestCase {
 *     public function testHello() {
 *         $this->get_page("post/list");                   // View a page, any page
 *         $this->assert_text("Hello there");              // Check that the specified text is in that page
 *     }
 * }
 *
 * // themes/mytheme/hello.theme.php
 * public class CustomHelloTheme extends HelloTheme {     // CustomHelloTheme overrides HelloTheme
 *     public function display_hello($username) {         // the display_hello() function is customised
 *         global $page;
 *         $h_user = html_escape($username);
 *         $page->add_block(new Block(
 *             "Hello!",
 *             "Hello there $h_user, look at my snazzy custom theme!"
 *         );
 *     }
 * }
 * \endcode
 *
 */

/**
 * Class Extension
 *
 * send_event(BlahEvent()) -> onBlah($event)
 *
 * Also loads the theme object into $this->theme if available
 *
 * The original concept came from Artanis's Extension extension
 * --> http://github.com/Artanis/simple-extension/tree/master
 * Then re-implemented by Shish after he broke the forum and couldn't
 * find the thread where the original was posted >_<
 */
abstract class Extension {
	/** @var array which DBs this ext supports (blank for 'all') */
	protected $db_support = [];

	/** @var Themelet this theme's Themelet object */
	public $theme;

	public function __construct() {
		$this->theme = $this->get_theme_object(get_called_class());
	}

	/**
	 * @return boolean
	 */
	public function is_live() {
		global $database;
		return (
			empty($this->db_support) ||
			in_array($database->get_driver_name(), $this->db_support)
		);
	}

	/**
	 * Find the theme object for a given extension.
	 *
	 * @param string $base
	 * @return Themelet
	 */
	private function get_theme_object($base) {
		$custom = 'Custom'.$base.'Theme';
		$normal = $base.'Theme';

		if(class_exists($custom)) {
			return new $custom();
		}
		elseif(class_exists($normal)) {
			return new $normal();
		}
		else {
			return null;
		}
	}

	/**
	 * Override this to change the priority of the extension,
	 * lower numbered ones will recieve events first.
	 *
	 * @return int
	 */
	public function get_priority() {
		return 50;
	}
}

/**
 * Class FormatterExtension
 *
 * Several extensions have this in common, make a common API.
 */
abstract class FormatterExtension extends Extension {
	/**
	 * @param TextFormattingEvent $event
	 */
	public function onTextFormatting(TextFormattingEvent $event) {
		$event->formatted = $this->format($event->formatted);
		$event->stripped  = $this->strip($event->stripped);
	}

	/**
	 * @param string $text
	 * @return string
	 */
	abstract public function format(/*string*/ $text);

	/**
	 * @param string $text
	 * @return string
	 */
	abstract public function strip(/*string*/ $text);
}

/**
 * Class DataHandlerExtension
 *
 * This too is a common class of extension with many methods in common,
 * so we have a base class to extend from.
 */
abstract class DataHandlerExtension extends Extension {
	/**
	 * @param DataUploadEvent $event
	 * @throws UploadException
	 */
	public function onDataUpload(DataUploadEvent $event) {
		$supported_ext = $this->supported_ext($event->type);
		$check_contents = $this->check_contents($event->tmpname);
		if($supported_ext && $check_contents) {
			move_upload_to_archive($event);
			send_event(new ThumbnailGenerationEvent($event->hash, $event->type));

			/* Check if we are replacing an image */
			if(array_key_exists('replace', $event->metadata) && isset($event->metadata['replace'])) {
				/* hax: This seems like such a dirty way to do this.. */

				/* Validate things */
				$image_id = int_escape($event->metadata['replace']);

				/* Check to make sure the image exists. */
				$existing = Image::by_id($image_id);

				if(is_null($existing)) {
					throw new UploadException("Image to replace does not exist!");
				}
				if ($existing->hash === $event->metadata['hash']) {
					throw new UploadException("The uploaded image is the same as the one to replace.");
				}

				// even more hax..
				$event->metadata['tags'] = $existing->get_tag_list();
				$image = $this->create_image_from_data(warehouse_path("images", $event->metadata['hash']), $event->metadata);

				if(is_null($image)) {
					throw new UploadException("Data handler failed to create image object from data");
				}

				$ire = new ImageReplaceEvent($image_id, $image);
				send_event($ire);
				$event->image_id = $image_id;
			}
			else {
				$image = $this->create_image_from_data(warehouse_path("images", $event->hash), $event->metadata);
				if(is_null($image)) {
					throw new UploadException("Data handler failed to create image object from data");
				}
				$iae = new ImageAdditionEvent($image);
				send_event($iae);
				$event->image_id = $iae->image->id;

				// Rating Stuff.
				if(!empty($event->metadata['rating'])){
					$rating = $event->metadata['rating'];
					send_event(new RatingSetEvent($image, $rating));
				}

				// Locked Stuff.
				if(!empty($event->metadata['locked'])){
					$locked = $event->metadata['locked'];
					send_event(new LockSetEvent($image, !empty($locked)));
				}
			}
		}
		elseif($supported_ext && !$check_contents){
			throw new UploadException("Invalid or corrupted file");
		}
	}

	/**
	 * @param ThumbnailGenerationEvent $event
	 */
	public function onThumbnailGeneration(ThumbnailGenerationEvent $event) {
		if($this->supported_ext($event->type)) {
			if (method_exists($this, 'create_thumb_force') && $event->force == true) {
				 $this->create_thumb_force($event->hash);
			}
			else {
				$this->create_thumb($event->hash);
			}
		}
	}

	/**
	 * @param DisplayingImageEvent $event
	 */
	public function onDisplayingImage(DisplayingImageEvent $event) {
		global $page;
		if($this->supported_ext($event->image->ext)) {
			$this->theme->display_image($page, $event->image);
		}
	}

	/*
	public function onSetupBuilding(SetupBuildingEvent $event) {
		$sb = $this->setup();
		if($sb) $event->panel->add_block($sb);
	}

	protected function setup() {}
	*/

	/**
	 * @param string $ext
	 * @return bool
	 */
	abstract protected function supported_ext($ext);

	/**
	 * @param string $tmpname
	 * @return bool
	 */
	abstract protected function check_contents($tmpname);

	/**
	 * @param string $filename
	 * @param array $metadata
	 * @return Image|null
	 */
	abstract protected function create_image_from_data($filename, $metadata);

	/**
	 * @param string $hash
	 * @return bool
	 */
	abstract protected function create_thumb($hash);
}

/**
 * Class ThumbHandlerExtension
 *
 * This is a DataHandlerExtension class which handles thumbs generation
 */
abstract class ThumbHandlerExtension extends DataHandlerExtension {
	/**
	 * @param string $inname
	 * @param string $outname
	 * @return bool
	 */
	protected function do_create_thumb(/*string*/ $inname, /*string*/ $outname) {
		global $config;

		$ok = false;

		switch($config->get_string("thumb_engine")) {
			default:
			case 'gd':
				$ok = $this->make_thumb_gd($inname, $outname);
				break;
			case 'convert':
				$ok = $this->make_thumb_convert($inname, $outname);
				break;
		}

		return $ok;
	}

// IM thumber {{{

	/**
	 * @param string $inname
	 * @param string $outname
	 * @return bool
	 */
	private function make_thumb_convert(/*string*/ $inname, /*string*/ $outname) {
		global $config;

		$w = $config->get_int("thumb_width");
		$h = $config->get_int("thumb_height");
		$q = $config->get_int("thumb_quality");
		$convert = $config->get_string("thumb_convert_path");

		//  ffff imagemagic fails sometimes, not sure why
		//$format = "'%s' '%s[0]' -format '%%[fx:w] %%[fx:h]' info:";
		//$cmd = sprintf($format, $convert, $inname);
		//$size = shell_exec($cmd);
		//$size = explode(" ", trim($size));
		$size = getimagesize($inname);
		if($size[0] > $size[1]*5) $size[0] = $size[1]*5;
		if($size[1] > $size[0]*5) $size[1] = $size[0]*5;

		// running the call with cmd.exe requires quoting for our paths
		$format = '"%s" "%s[0]" -extent %ux%u -flatten -strip -thumbnail %ux%u -quality %u jpg:"%s"';
		$cmd = sprintf($format, $convert, $inname, $size[0], $size[1], $w, $h, $q, $outname);
		$cmd = str_replace("\"convert\"", "convert", $cmd); // quotes are only needed if the path to convert contains a space; some other times, quotes break things, see github bug #27
		exec($cmd, $output, $ret);

		log_debug('handle_pixel', "Generating thumnail with command `$cmd`, returns $ret");

		if($config->get_bool("thumb_optim", false)) {
			exec("jpegoptim $outname", $output, $ret);
		}

		return true;
	}
// }}}
// epeg thumber {{{
	/**
	 * @param string $inname
	 * @param string $outname
	 * @return bool
	 */
	private function make_thumb_epeg(/*string*/ $inname, /*string*/ $outname) {
		global $config;
		$w = $config->get_int("thumb_width");
		exec("epeg $inname -c 'Created by EPEG' --max $w $outname");
		return true;
	}
	// }}}
// GD thumber {{{
	/**
	 * @param string $inname
	 * @param string $outname
	 * @return bool
	 */
	private function make_thumb_gd(/*string*/ $inname, /*string*/ $outname) {
		global $config;
		$thumb = $this->get_thumb($inname);
		$ok = imagejpeg($thumb, $outname, $config->get_int('thumb_quality'));
		imagedestroy($thumb);
		return $ok;
	}

	/**
	 * @param string $tmpname
	 * @return resource
	 */
	private function get_thumb(/*string*/ $tmpname) {
		global $config;

		$info = getimagesize($tmpname);
		$width = $info[0];
		$height = $info[1];

		$memory_use = (filesize($tmpname)*2) + ($width*$height*4) + (4*1024*1024);
		$memory_limit = get_memory_limit();

		if($memory_use > $memory_limit) {
			$w = $config->get_int('thumb_width');
			$h = $config->get_int('thumb_height');
			$thumb = imagecreatetruecolor($w, min($h, 64));
			$white = imagecolorallocate($thumb, 255, 255, 255);
			$black = imagecolorallocate($thumb, 0,   0,   0);
			imagefill($thumb, 0, 0, $white);
			imagestring($thumb, 5, 10, 24, "Image Too Large :(", $black);
			return $thumb;
		}
		else {
			if($width > $height*5) $width = $height*5;
			if($height > $width*5) $height = $width*5;

			$image = imagecreatefromstring(file_get_contents($tmpname));
			$tsize = get_thumbnail_size($width, $height);

			$thumb = imagecreatetruecolor($tsize[0], $tsize[1]);
			imagecopyresampled(
					$thumb, $image, 0, 0, 0, 0,
					$tsize[0], $tsize[1], $width, $height
					);
			return $thumb;
		}
	}
// }}}
}

