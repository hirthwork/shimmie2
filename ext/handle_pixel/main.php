<?php
/**
 * Name: Handle Pixel
 * Author: Shish <webmaster@shishnet.org>
 * Link: http://code.shishnet.org/shimmie2/
 * Description: Handle JPEG, PNG, GIF, etc files
 */

class PixelFileHandler extends ThumbHandlerExtension {
	/**
	 * @param string $ext
	 * @return bool
	 */
	protected function supported_ext($ext) {
		$exts = array("jpg", "jpeg", "gif", "png");
		$ext = (($pos = strpos($ext,'?')) !== false) ? substr($ext,0,$pos) : $ext;
		return in_array(strtolower($ext), $exts);
	}

	/**
	 * @param string $filename
	 * @param array $metadata
	 * @return Image|null
	 */
	protected function create_image_from_data(/*string*/ $filename, /*array*/ $metadata) {
		$image = new Image();

		$info = getimagesize($filename);
		if(!$info) return null;

		$image->width = $info[0];
		$image->height = $info[1];

		$image->filesize  = $metadata['size'];
		$image->hash      = $metadata['hash'];
		$image->filename  = (($pos = strpos($metadata['filename'],'?')) !== false) ? substr($metadata['filename'],0,$pos) : $metadata['filename'];
		$image->ext       = (($pos = strpos($metadata['extension'],'?')) !== false) ? substr($metadata['extension'],0,$pos) : $metadata['extension'];
		$image->tag_array = is_array($metadata['tags']) ? $metadata['tags'] : Tag::explode($metadata['tags']);
		$image->source    = $metadata['source'];

		return $image;
	}

	/**
	 * @param string $file
	 * @return bool
	 */
	protected function check_contents(/*string*/ $file) {
		$valid = Array(IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG);
		if(!file_exists($file)) return false;
		$info = getimagesize($file);
		if(is_null($info)) return false;
		if(in_array($info[2], $valid)) return true;
		return false;
	}

	/**
	 * @param string $hash
	 * @return bool
	 */
	protected function create_thumb(/*string*/ $hash) {
		$outname = warehouse_path("thumbs", $hash);
		if(file_exists($outname)) {
			return true;
		}
		return $this->create_thumb_force($hash);
	}

	/**
	 * @param string $hash
	 * @return bool
	 */
	protected function create_thumb_force(/*string*/ $hash) {
		$inname  = warehouse_path("images", $hash);
		$outname = warehouse_path("thumbs", $hash);

		return $this->do_create_thumb($inname, $outname);
	}

	public function onImageAdminBlockBuilding(ImageAdminBlockBuildingEvent $event) {
		$event->add_part("
			<form>
				<select class='shm-zoomer'>
					<option value='full'>Full Size</option>
					<option value='width'>Fit Width</option>
					<option value='height'>Fit Height</option>
					<option value='both'>Fit Both</option>
				</select>
			</form>
		", 20);

		$u_ilink = $event->image->get_image_link();
		$nu_enabled = (strpos($u_ilink, '?') !== false ? "<input type='hidden' name='q' value='image/{$event->image->id}.{$event->image->ext}' />" : "");
		$event->add_part("
			<form action='{$u_ilink}'>
				$nu_enabled
				<input type='submit' value='Image Only'>
			</form>
		", 21);
	}
}

