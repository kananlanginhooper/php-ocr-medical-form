#!/usr/bin/env python3
import argparse
import json
import os
import cv2
from paddleocr import PaddleOCR

from crop_image import crop_image
from run_ocr import run_ocr

WIP_CROP_PATH = "paddle_wip.jpg"


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

    img_h = img.shape[0]
    crop_path = os.path.join(os.getcwd(), WIP_CROP_PATH)
    ocr = PaddleOCR(lang='en', use_textline_orientation=True)

    # Pass 1: exact bbox
    path = crop_image(img, args.x, args.y, args.w, args.h, crop_path)
    if path is None:
        print("[]")
        return
    output = run_ocr(path, ocr)

    # Pass 2 fallback: expand vertically to a minimum readable height.
    if not output and args.h < 140:
        target_h = min(img_h, 140)
        extra = max(0, target_h - args.h)
        y0 = max(0, args.y - (extra // 2))
        y1 = min(img_h, args.y + args.h + (extra - (extra // 2)))
        path = crop_image(img, args.x, y0, args.w, y1 - y0, crop_path)
        if path:
            output = run_ocr(path, ocr)

    # Pass 3 fallback: broader expansion for very tight boxes.
    if not output and args.h < 200:
        target_h = min(img_h, 200)
        extra = max(0, target_h - args.h)
        y0 = max(0, args.y - (extra // 2))
        y1 = min(img_h, args.y + args.h + (extra - (extra // 2)))
        path = crop_image(img, args.x, y0, args.w, y1 - y0, crop_path)
        if path:
            output = run_ocr(path, ocr)

    print(json.dumps(output, indent=2))

if __name__ == "__main__":
    main()
