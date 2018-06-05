<?php
/*
 * Name: Handle PDF
 * Author: Dmitry Potapov <potapov.d@gmail.com>
 * License: GPLv2
 * Description: PDF files.
 * Documentation:
 *  Inspired by "Handle Video" by velocity37.<br><br>
 */

class PdfFileHandler extends ThumbHandlerExtension {
	public function onInitExt(InitExtEvent $event) {
		global $config;
		$resolve = PHP_OS == 'WINNT' ? 'where' : 'which';
		$pdftoppm = shell_exec($resolve . ' pdftoppm');
		if (is_executable(strtok($pdftoppm, PHP_EOL))) {
			$config->set_default_string('pdf_thumb_engine', 'pdftoppm');
			$config->set_default_string('thumb_pdftoppm_path', 'pdftoppm');
		} else {
			$config->set_default_string('pdf_thumb_engine', 'static');
			$config->set_default_string('thumb_pdftoppm_path', '');
		}
	}

	public function onSetupBuilding(SetupBuildingEvent $event) {
		$thumbers = array(
			'None'	    => 'static',
			'pdftoppm'  => 'pdftoppm'
		);
		$sb = new SetupBlock("PDF Thumbnail Options");
		$sb->add_choice_option("pdf_thumb_engine", $thumbers, "Engine: ");
		$sb->add_label("<br>Path to pdftoppm: ");
		$sb->add_text_option("thumb_pdftoppm_path");
		$event->panel->add_block($sb);
	}

	private function create_static_thumb($hash) {
		$inname = "ext/handle_pdf/thumb.jpg";
		$outname = warehouse_path("thumbs", $hash);
		return $this->do_create_thumb($inname, $outname);
	}

	protected function create_thumb($hash) {
		global $config;

		$ok = false;

		switch ($config->get_string("pdf_thumb_engine")) {
			default:
			case 'static':
				$ok = $this->create_static_thumb($hash);
				break;
			case 'pdftoppm':
				// TODO: Respect jpg ext
				$tmp_filename = tempnam(ini_get('upload_tmp_dir'), 'shimmie_pdf_thumb');
				if (empty($tmp_filename)) {
					$ok = $this->create_static_thumb($hash);
				} else {
					$pdftoppm = escapeshellcmd($config->get_string('thumb_pdftoppm_path'));
					$inname = escapeshellarg(warehouse_path('images', $hash));
					$cmd = "${pdftoppm} -jpeg -singlefile " . $inname . ' ' . escapeshellarg($tmp_filename);
					exec($cmd, $output, $returnValue);
					if ((int) $returnValue == (int) 0) {
						$tmp_jpegfilename = $tmp_filename . ".jpg";
						$outname = warehouse_path("thumbs", $hash);
						$ok = $this->do_create_thumb($tmp_jpegfilename, $outname);
						unlink($tmp_jpgfilename);
					}
					unlink($tmp_filename);
				}
		}
		return $ok;
	}

	/**
	 * @param string $ext
	 * @return bool
	 */
	protected function supported_ext($ext) {
		return $ext == "pdf";
	}

	/**
	 * @param string $filename
	 * @param mixed[] $metadata
	 * @return Image
	 */
	protected function create_image_from_data($filename, $metadata) {
		global $config;

		$image = new Image();

		//NOTE: No need to set width/height as we don't use it.
		$image->width  = 1;
		$image->height = 1;

		if (mime_content_type($filename) == 'application/pdf') {
			$image->ext = "pdf";
		} else {
			return null;
		}

		$image->filesize  = $metadata['size'];
		$image->hash      = $metadata['hash'];
		$image->filename  = $metadata['filename'];
		$image->tag_array = is_array($metadata['tags']) ? $metadata['tags'] : Tag::explode($metadata['tags']);
		$image->source    = $metadata['source'];

		return $image;
	}

	/**
	 * @param string $file
	 * @return bool
	 */
	protected function check_contents($file) {
		$success = FALSE;
		if (file_exists($file)) {
			$mimeType = mime_content_type($file);
			$success = $mimeType == 'application/pdf';
		}

		return $success;
	}
}

