from paddleocr import PaddleOCR


def run_ocr(image_path, ocr=None):
    """Run PaddleOCR on image_path and return matches.

    Args:
        image_path: Path to the image file.
        ocr: Optional pre-initialised PaddleOCR instance. If None, one is
             created with lang='en' and use_textline_orientation=True.

    Returns:
        List of {"text": str, "confidence": float} dicts.
    """
    if ocr is None:
        ocr = PaddleOCR(lang='en', use_textline_orientation=True)

    result = ocr.predict(image_path)

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
            output.append({"text": text, "confidence": score})

    return output
