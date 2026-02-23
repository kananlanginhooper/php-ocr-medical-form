#!/usr/bin/env python3
import argparse
import json
import os
import cv2
from paddleocr import PaddleOCR

WIP_CROP_PATH = "paddle_wip.jpg"

def run_predict(ocr, crop):
    if crop is None or crop.size == 0:
        return []

    # Persist the latest crop to a stable file for easier local testing/debugging.
    crop_path = os.path.join(os.getcwd(), WIP_CROP_PATH)
    cv2.imwrite(crop_path, crop)
    result = ocr.predict(crop_path)

    output = []
    for item in result:
        data = item.to_dict() if hasattr(item, "to_dict") else item
        if not isinstance(data, dict):
            continue

        texts = data.get("rec_texts") or []
        scores = data.get("rec_scores") or []
        for i, text in enumerate(texts):
            text = (text or "").strip()
            if not text:
                continue
            score = float(scores[i]) if i < len(scores) else 0.0
            output.append({
                "text": text,
                "confidence": score
            })

    return output


def main():
    parser = argparse.ArgumentParser(description="Run PaddleOCR on a cropped bounding box.")
    parser.add_argument("image", help="Path to input JPG image")
    parser.add_argument("--x", type=int, required=True, help="X coordinate of bounding box")
    parser.add_argument("--y", type=int, required=True, help="Y coordinate of bounding box")
    parser.add_argument("--w", type=int, required=True, help="Width of bounding box")
    parser.add_argument("--h", type=int, required=True, help="Height of bounding box")
    args = parser.parse_args()

    # Load image
    img = cv2.imread(args.image)
    if img is None:
        raise FileNotFoundError(f"Could not load image: {args.image}")

    # Crop bounding box (clamped to image bounds)
    img_h, img_w = img.shape[:2]
    x = max(0, min(args.x, img_w - 1))
    y = max(0, min(args.y, img_h - 1))
    w = max(1, args.w)
    h = max(1, args.h)
    x1 = min(img_w, x + w)
    y1 = min(img_h, y + h)
    crop = img[y:y1, x:x1]
    if crop.size == 0:
        print("[]")
        return

    # Initialize OCR
    ocr = PaddleOCR(
        lang='en',
        use_textline_orientation=True
    )

    # Pass 1: exact bbox
    output = run_predict(ocr, crop)

    # Pass 2 fallback: if empty, expand vertically around the same center to a minimum readable height.
    if not output and h < 140:
        target_h = min(img_h, 140)
        extra = max(0, target_h - h)
        y0 = max(0, y - (extra // 2))
        y1b = min(img_h, y1 + (extra - (extra // 2)))
        expanded = img[y0:y1b, x:x1]
        output = run_predict(ocr, expanded)

    # Pass 3 fallback: broader expansion for very tight boxes.
    if not output and h < 200:
        target_h = min(img_h, 200)
        extra = max(0, target_h - h)
        y0 = max(0, y - (extra // 2))
        y1c = min(img_h, y1 + (extra - (extra // 2)))
        expanded2 = img[y0:y1c, x:x1]
        output = run_predict(ocr, expanded2)

    print(json.dumps(output, indent=2))

if __name__ == "__main__":
    main()
