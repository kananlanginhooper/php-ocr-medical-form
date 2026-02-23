#!/usr/bin/env python3
import argparse
import json
import os
import cv2
from paddleocr import PaddleOCR

from crop_image import crop_image
from run_ocr import run_ocr

WIP_CROP_PASS1 = "paddle_wip_pass1.jpg"
WIP_CROP_PASS2 = "paddle_wip_pass2.jpg"
WIP_CROP_PASS3 = "paddle_wip_pass3.jpg"


def pass_exact(img, x, y, w, h, ocr):
    """Crop and OCR the exact bounding box as given."""
    path = crop_image(img, x, y, w, h, os.path.join(os.getcwd(), WIP_CROP_PASS1))
    if path is None:
        return []
    return run_ocr(path, ocr)


def pass_expand_140(img, x, y, w, h, ocr):
    """Crop and OCR with the bounding box expanded vertically to at least 140px."""
    img_h = img.shape[0]
    target_h = min(img_h, 140)
    extra = max(0, target_h - h)
    y0 = max(0, y - (extra // 2))
    y1 = min(img_h, y + h + (extra - (extra // 2)))
    path = crop_image(img, x, y0, w, y1 - y0, os.path.join(os.getcwd(), WIP_CROP_PASS2))
    if path is None:
        return []
    return run_ocr(path, ocr)


def pass_expand_200(img, x, y, w, h, ocr):
    """Crop and OCR with the bounding box expanded vertically to at least 200px."""
    img_h = img.shape[0]
    target_h = min(img_h, 200)
    extra = max(0, target_h - h)
    y0 = max(0, y - (extra // 2))
    y1 = min(img_h, y + h + (extra - (extra // 2)))
    path = crop_image(img, x, y0, w, y1 - y0, os.path.join(os.getcwd(), WIP_CROP_PASS3))
    if path is None:
        return []
    return run_ocr(path, ocr)


def best_result(*results):
    """Return the result with the most matches, breaking ties by total confidence."""
    return max(results, key=lambda r: (len(r), sum(item["confidence"] for item in r)))


def main():
    parser = argparse.ArgumentParser(description="Run PaddleOCR on a cropped bounding box.")
    parser.add_argument("image", help="Path to input JPG image")
    parser.add_argument("--x", type=int, required=True, help="X coordinate of bounding box")
    parser.add_argument("--y", type=int, required=True, help="Y coordinate of bounding box")
    parser.add_argument("--w", type=int, required=True, help="Width of bounding box")
    parser.add_argument("--h", type=int, required=True, help="Height of bounding box")
    args = parser.parse_args()

    img = cv2.imread(args.image)
    if img is None:
        raise FileNotFoundError(f"Could not load image: {args.image}")

    ocr = PaddleOCR(lang='en', use_textline_orientation=True)

    result1 = pass_exact(img, args.x, args.y, args.w, args.h, ocr)
    result2 = pass_expand_140(img, args.x, args.y, args.w, args.h, ocr)
    result3 = pass_expand_200(img, args.x, args.y, args.w, args.h, ocr)

    output = best_result(result1, result2, result3)

    print(json.dumps(output, indent=2))

if __name__ == "__main__":
    main()
