import cv2


def crop_image(img, x, y, w, h, output_path):
    """Crop a bounding box from img (numpy array) and save to output_path.

    Coordinates are clamped to image bounds. Returns the saved path,
    or None if the resulting crop is empty.
    """
    img_h, img_w = img.shape[:2]
    x = max(0, min(x, img_w - 1))
    y = max(0, min(y, img_h - 1))
    w = max(1, w)
    h = max(1, h)
    x1 = min(img_w, x + w)
    y1 = min(img_h, y + h)
    crop = img[y:y1, x:x1]

    if crop.size == 0:
        return None

    cv2.imwrite(output_path, crop)
    return output_path
