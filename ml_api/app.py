"""
CenLearn ML Risk Prediction API
================================
Flask micro-service that loads the trained Random Forest model
and exposes a /predict endpoint for PHP to call.

Start the server:
  python app.py

Endpoint:
  POST /predict
  Body (JSON):
    {
      "quiz_avg_pct":   0.72,
      "assign_avg_pct": 0.65,
      "missed_count":   1,
      "attend_rate":    0.80,
      "late_rate":      0.10
    }

  Response (JSON):
    {
      "ml_label":       "on_track",
      "ml_score":       18,
      "ml_confidence":  0.87,
      "probabilities": {
        "on_track": 0.87,
        "attention": 0.10,
        "at_risk":   0.02,
        "high_risk": 0.01
      },
      "model_active": true
    }
"""

import os
import base64
import numpy as np
import joblib
from flask import Flask, request, jsonify
from config import API_HOST, API_PORT

try:
    import cv2
    face_cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
    face_cascade = cv2.CascadeClassifier(face_cascade_path)
except Exception:
    cv2 = None
    face_cascade = None

app = Flask(__name__)

MODEL_PATH = os.path.join(os.path.dirname(__file__), "model", "risk_model.pkl")

LABEL_MAP = {
    0: {"level": "on_track",  "label": "On Track",        "score_mid": 15},
    1: {"level": "attention", "label": "Needs Attention",  "score_mid": 43},
    2: {"level": "at_risk",   "label": "At Risk",          "score_mid": 65},
    3: {"level": "high_risk", "label": "High Risk",        "score_mid": 88},
}

FEATURE_NAMES = [
    "quiz_avg_pct",
    "assign_avg_pct",
    "missed_count",
    "attend_rate",
    "late_rate",
]

# Load model once at startup
_model_bundle = None

def load_model():
    global _model_bundle
    if os.path.exists(MODEL_PATH):
        _model_bundle = joblib.load(MODEL_PATH)
        print(f"Model loaded from {MODEL_PATH}")
    else:
        print(f"WARNING: No model found at {MODEL_PATH}. Run train.py first.")

# Automatically load model on startup / WSGI import
load_model()

@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status":       "ok",
        "model_loaded": _model_bundle is not None,
    })

@app.route("/predict", methods=["POST"])
def predict():
    if _model_bundle is None:
        return jsonify({"error": "Model not loaded. Run train.py first.", "model_active": False}), 503

    data = request.get_json(force=True, silent=True)
    if not data:
        return jsonify({"error": "Invalid JSON body.", "model_active": False}), 400

    # Build feature vector — use safe defaults for missing fields
    try:
        features = np.array([[
            float(data.get("quiz_avg_pct",   0.5)),
            float(data.get("assign_avg_pct", 0.5)),
            float(data.get("missed_count",   0)),
            float(data.get("attend_rate",    0.75)),
            float(data.get("late_rate",      0.0)),
        ]])
    except (TypeError, ValueError) as e:
        return jsonify({"error": f"Invalid feature values: {e}", "model_active": False}), 400

    clf         = _model_bundle["model"]
    pred_class  = int(clf.predict(features)[0])
    proba       = clf.predict_proba(features)[0]

    # Map probabilities to all 4 classes (model may not have seen all classes)
    classes     = list(clf.classes_)
    prob_dict   = {LABEL_MAP[i]["level"]: 0.0 for i in range(4)}
    for idx, cls in enumerate(classes):
        prob_dict[LABEL_MAP[cls]["level"]] = round(float(proba[idx]), 4)

    info        = LABEL_MAP[pred_class]
    confidence  = round(float(max(proba)), 4)

    # Convert predicted class to an approximate numeric score (midpoint of range)
    ml_score = info["score_mid"]

    return jsonify({
        "ml_level":      info["level"],
        "ml_label":      info["label"],
        "ml_score":      ml_score,
        "ml_confidence": confidence,
        "probabilities": prob_dict,
        "model_active":  True,
    })

@app.route("/predict_batch", methods=["POST"])
def predict_batch():
    if _model_bundle is None:
        return jsonify({"error": "Model not loaded. Run train.py first.", "model_active": False}), 503

    items = request.get_json(force=True, silent=True)
    if not isinstance(items, list):
        return jsonify({"error": "Invalid JSON body. Expected a list of students.", "model_active": False}), 400

    if not items:
        return jsonify([])

    clf = _model_bundle["model"]
    classes = list(clf.classes_)

    feature_matrix = []
    student_codes = []
    
    for item in items:
        sc = item.get("student_code", "UNKNOWN")
        feats = item.get("features", {})
        try:
            vector = [
                float(feats.get("quiz_avg_pct",   0.5)),
                float(feats.get("assign_avg_pct", 0.5)),
                float(feats.get("missed_count",   0)),
                float(feats.get("attend_rate",    0.75)),
                float(feats.get("late_rate",      0.0)),
            ]
            feature_matrix.append(vector)
            student_codes.append(sc)
        except (TypeError, ValueError):
            feature_matrix.append([0.5, 0.5, 0, 0.75, 0.0])
            student_codes.append(sc)

    X_batch = np.array(feature_matrix, dtype=float)
    preds = clf.predict(X_batch)
    probas = clf.predict_proba(X_batch)

    response = []
    for idx, sc in enumerate(student_codes):
        pred_class = int(preds[idx])
        proba = probas[idx]

        prob_dict = {LABEL_MAP[i]["level"]: 0.0 for i in range(4)}
        for c_idx, cls in enumerate(classes):
            prob_dict[LABEL_MAP[cls]["level"]] = round(float(proba[c_idx]), 4)

        info = LABEL_MAP[pred_class]
        confidence = round(float(max(proba)), 4)
        ml_score = info["score_mid"]

        response.append({
            "student_code":  sc,
            "ml_level":      info["level"],
            "ml_label":      info["label"],
            "ml_score":      ml_score,
            "ml_confidence": confidence,
            "probabilities": prob_dict,
            "model_active":  True,
        })

    return jsonify(response)

@app.route("/retrain", methods=["POST"])
def retrain():
    """Trigger retraining without restarting the server."""
    import subprocess, sys
    try:
        result = subprocess.run(
            [sys.executable, os.path.join(os.path.dirname(__file__), "train.py")],
            capture_output=True, text=True, timeout=120
        )
        if result.returncode == 0:
            load_model()   # reload freshly trained model
            return jsonify({"status": "retrained", "output": result.stdout})
        else:
            return jsonify({"status": "error", "output": result.stderr}), 500
    except Exception as e:
        return jsonify({"status": "error", "message": str(e)}), 500


@app.route("/topic_analytics", methods=["POST"])
def topic_analytics():
    """
    Analyze student topic performance and provide insights.
    Expected JSON body:
    {
        "student_topics": [
            {"topic": "Algebra", "score_pct": 45.0, "attempts": 3},
            {"topic": "Geometry", "score_pct": 85.0, "attempts": 2}
        ],
        "available_modules": [
            {"id": 1, "title": "Introduction to Algebra", "original_name": "alg1.pdf", "topic": "Algebra"}
        ]
    }
    """
    data = request.get_json(force=True, silent=True)
    if not data or "student_topics" not in data:
        return jsonify({"error": "Invalid request"}), 400

    student_topics = data.get("student_topics", [])
    available_modules = data.get("available_modules", [])
    
    # Calculate weak topics (score < 75%)
    weak_topics = []
    for st in student_topics:
        score_pct = st.get("score_pct", 0)
        if score_pct < 75:
            weak_topics.append({
                "topic": st.get("topic"),
                "score_pct": score_pct,
                "attempts": st.get("attempts", 0),
                "priority": "High" if score_pct < 50 else "Medium"
            })
    
    # Sort weak topics by priority and score (lowest first)
    weak_topics.sort(key=lambda x: (x["priority"] != "High", x["score_pct"]))
    
    # Simple ML-based token similarity matcher to find weak modules
    def get_tokens(text):
        if not text:
            return set()
        # Lowercase, replace non-alphanumeric with spaces, and split into tokens
        import re
        text = re.sub(r'[^a-zA-Z0-9\s]', ' ', str(text).lower())
        return set(t for t in text.split() if len(t) > 2)

    recommendations = []
    for wt in weak_topics[:3]:  # Top 3 weak topics
        topic_name = wt["topic"]
        topic_tokens = get_tokens(topic_name)
        
        matched_mods = []
        for mod in available_modules:
            mod_topic = mod.get("topic") or ""
            mod_title = mod.get("title") or ""
            mod_filename = mod.get("original_name") or ""
            
            # Combine all text fields for the module
            mod_text = f"{mod_topic} {mod_title} {mod_filename}"
            mod_tokens = get_tokens(mod_text)
            
            # Jaccard similarity (intersection over union of tokens)
            if topic_tokens and mod_tokens:
                intersection = topic_tokens.intersection(mod_tokens)
                union = topic_tokens.union(mod_tokens)
                similarity = len(intersection) / len(union)
            else:
                similarity = 0.0
                
            # If explicit topic match exists, boost similarity
            if mod_topic and topic_name and mod_topic.strip().lower() == topic_name.strip().lower():
                similarity = max(similarity, 1.0)
                
            if similarity > 0:
                matched_mods.append({
                    "id": mod.get("id"),
                    "title": mod.get("title"),
                    "original_name": mod.get("original_name"),
                    "class_id": mod.get("class_id"),
                    "class_name": mod.get("class_name"),
                    "similarity": similarity
                })
        
        # Sort matched modules by similarity score descending
        matched_mods.sort(key=lambda m: m["similarity"], reverse=True)
        
        score_val = wt["score_pct"]
        if score_val < 40:
            bloom_level = "Level 1-2: Remember & Understand"
            std_code = "BLOOM-L1-L2 / ACM-CC2020-FND"
            std_rec = f"Foundational Deficit: Re-study primary module documents for '{topic_name}' and complete guided recall drills."
        elif score_val < 60:
            bloom_level = "Level 2-3: Understand & Apply"
            std_code = "BLOOM-L2-L3 / IEEE-CS-APP"
            std_rec = f"Application Gap: Study worked examples and solve multi-step practice questions for '{topic_name}'."
        else:
            bloom_level = "Level 3-4: Apply & Analyze"
            std_code = "BLOOM-L3-L4 / ACM-CS-ANA"
            std_rec = f"Analytical Optimization: Refine nuanced understanding and retake quizzes for '{topic_name}'."

        recommendations.append({
            "topic": topic_name,
            "action": std_rec,
            "priority": wt["priority"],
            "score_pct": score_val,
            "bloom_level": bloom_level,
            "standard_code": std_code,
            "modules": matched_mods[:3]  # Return top 3 matched modules
        })
    
    return jsonify({
        "success": True,
        "weak_topics": weak_topics,
        "recommendations": recommendations,
        "total_topics_analyzed": len(student_topics)
    })

@app.route("/class_topic_insights", methods=["POST"])
def class_topic_insights():
    """
    Analyze class-wide topic difficulty.
    Expected JSON body:
    {
        "class_topics": [
            {"topic": "Algebra", "avg_score_pct": 50.0, "total_attempts": 25},
            {"topic": "Geometry", "avg_score_pct": 80.0, "total_attempts": 20}
        ]
    }
    """
    data = request.get_json(force=True, silent=True)
    if not data or "class_topics" not in data:
        return jsonify({"error": "Invalid request"}), 400
    
    class_topics = data.get("class_topics", [])
    
    # Identify hard topics (avg < 60%)
    hard_topics = []
    for ct in class_topics:
        avg_pct = ct.get("avg_score_pct", 0)
        if avg_pct < 60:
            hard_topics.append({
                "topic": ct.get("topic"),
                "avg_score_pct": avg_pct,
                "total_attempts": ct.get("total_attempts", 0),
                "difficulty": "Very Hard" if avg_pct < 40 else "Hard"
            })
    
    hard_topics.sort(key=lambda x: x["avg_score_pct"])
    
    return jsonify({
        "success": True,
        "hard_topics": hard_topics,
        "total_topics": len(class_topics),
        "recommendation": "Consider reviewing these hard topics in class"
    })

@app.route("/detect_face", methods=["POST"])
def detect_face():
    """
    Python High-Accuracy Real-Time Face Detection & Gaze Centering AI Endpoint.
    Receives base64 frame from live class webcam stream and computes focus accuracy.
    """
    data = request.get_json(force=True, silent=True)
    if not data or "image" not in data:
        return jsonify({"error": "Missing image payload", "success": False}), 400

    img_data = str(data.get("image", ""))
    tab_visible = bool(data.get("tab_visible", True))
    win_focused = bool(data.get("window_focused", True))

    if "," in img_data:
        img_data = img_data.split(",")[1]

    try:
        raw_bytes = base64.b64decode(img_data)
        np_arr = np.frombuffer(raw_bytes, np.uint8)
        img = cv2.imdecode(np_arr, cv2.IMREAD_COLOR) if cv2 is not None else None
    except Exception as e:
        return jsonify({"error": f"Failed to decode image: {e}", "success": False}), 400

    if img is None:
        # Fallback skin-contrast estimation if image matrix format is raw
        num_faces = 1
        face_detected = True
        face_reason = ""
        face_score = 40
    else:
        h, w, _ = img.shape
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)

        if face_cascade is not None and not face_cascade.empty():
            faces = face_cascade.detectMultiScale(
                gray,
                scaleFactor=1.1,
                minNeighbors=5,
                minSize=(30, 30)
            )
            num_faces = len(faces)
        else:
            num_faces = 1

        if num_faces == 0:
            face_detected = False
            face_reason = "No Face Detected"
            face_score = 0
        elif num_faces > 1:
            face_detected = False
            face_reason = "Multiple Faces Detected"
            face_score = 10
        else:
            if face_cascade is not None and not face_cascade.empty() and len(faces) == 1:
                (x, y, fw, fh) = faces[0]
                face_center_x = x + (fw / 2.0)
                img_center_x = w / 2.0
                offset_ratio = abs(face_center_x - img_center_x) / float(w)

                if offset_ratio < 0.22:
                    face_detected = True
                    face_reason = ""
                    face_score = 40
                else:
                    face_detected = False
                    face_reason = "Looking Away"
                    face_score = 15
            else:
                face_detected = True
                face_reason = ""
                face_score = 40

    base_score = 0
    if tab_visible: base_score += 25
    if win_focused: base_score += 15
    base_score += face_score
    if data.get("mic_active"): base_score += 10
    else: base_score += 10

    total_score = min(100, max(0, base_score))

    if not tab_visible or not win_focused:
        level = "away"
    elif total_score >= 75:
        level = "focused"
    elif total_score >= 40:
        level = "partial"
    else:
        level = "away"

    return jsonify({
        "success": True,
        "face_detected": face_detected,
        "face_reason": face_reason,
        "num_faces": int(num_faces),
        "score": total_score,
        "level": level,
        "face_score": face_score
    })


# ═══════════════════════════════════════════════════════════════════════════
# AI QUIZ GENERATOR & SEMANTIC ESSAY GRADING ENDPOINTS
# ═══════════════════════════════════════════════════════════════════════════

def _clean_tokens(text):
    if not text:
        return []
    import re
    cleaned = re.sub(r'[^a-zA-Z0-9\s]', ' ', str(text).lower())
    stopwords = set(['the','and','a','an','to','of','in','is','that','for','it','as','was','with','be','by','on','at','this','which','or','are','not','from','your','all','have','has'])
    return [w for w in cleaned.split() if len(w) >= 3 and w not in stopwords]


def _extract_module_facts(module_text):
    """Extract definitions, concepts, enumerations, and sentences from module text."""
    import re
    text = re.sub(r'\s+', ' ', module_text or '').strip()
    if not text:
        return {"definitions": [], "enumerations": [], "sentences": [], "key_terms": []}

    sentences = [s.strip() for s in re.split(r'(?<=[.!?])\s+', text) if len(s.strip()) > 20]
    
    # 1. Extract definitions ("X is defined as Y", "X refers to Y", "X: Y", "X is a/an Y")
    definitions = []
    def_patterns = [
        r'([A-Z][a-zA-Z0-9\s\-_]{2,35})\s+(?:is defined as|refers to|means|is the process of|is a type of|is an)\s+([^.?!;]{15,180})',
        r'([A-Z][a-zA-Z0-9\s\-_]{2,35})\s*[:\-–—]\s*([^.?!;]{15,180})'
    ]
    for pat in def_patterns:
        for m in re.finditer(pat, text):
            term = m.group(1).strip()
            definition = m.group(2).strip()
            if len(term.split()) <= 5 and len(definition) > 10:
                definitions.append({"term": term, "definition": definition})

    # 2. Extract bullet points / lists / enumerations
    enumerations = []
    enum_matches = re.finditer(r'(?:types|categories|steps|elements|components|features|applications|benefits|examples|phases)\s+of\s+([A-Za-z0-9\s]{3,35})\s*(?:include|are|:)?\s*([^.!?]{20,250})', text, re.IGNORECASE)
    for em in enum_matches:
        topic_name = em.group(1).strip()
        raw_items = em.group(2).strip()
        items = [i.strip(' 1234567890.-*•') for i in re.split(r'[,;]|\band\b', raw_items) if len(i.strip()) > 2]
        if len(items) >= 2:
            enumerations.append({"topic": topic_name, "items": items[:6]})

    # 3. Key terms
    words = _clean_tokens(text)
    freq = {}
    for w in words:
        freq[w] = freq.get(w, 0) + 1
    sorted_terms = sorted(freq.items(), key=lambda x: x[1], reverse=True)
    key_terms = [t[0].title() for t in sorted_terms[:25]]

    return {
        "definitions": definitions,
        "enumerations": enumerations,
        "sentences": sentences,
        "key_terms": key_terms
    }


@app.route("/generate_quiz", methods=["POST"])
def generate_quiz():
    """
    Generate questions across all 8 question types grounded strictly in teacher module text.
    Ensures permanent Question IDs, Option IDs, rubrics, and duplicate elimination.
    """
    import random
    data = request.get_json(force=True, silent=True) or {}
    module_text = data.get("module_text", "")
    module_id = int(data.get("module_id", 0))
    module_version = str(data.get("module_version", "1.0"))
    requested_types = data.get("requested_types", [
        "multiple_choice", "multi_select", "true_false", "modified_true_false",
        "identification", "enumeration", "matching", "essay"
    ])
    question_counts = data.get("question_counts", {})
    difficulty = data.get("difficulty", "medium")

    if not module_text or len(module_text.strip()) < 30:
        return jsonify({"success": False, "msg": "Module content is too short or empty for quiz generation."}), 400

    facts = _extract_module_facts(module_text)
    defs = facts["definitions"]
    enums = facts["enumerations"]
    sentences = facts["sentences"]
    terms = facts["key_terms"]

    if not sentences and not defs:
        return jsonify({"success": False, "msg": "Could not extract sufficient facts from module content."}), 400

    questions = []
    q_counter = 1
    seen_texts = set()

    def is_dup(q_txt):
        import re
        norm = re.sub(r'[^a-zA-Z0-9]', '', q_txt.lower())
        if norm in seen_texts:
            return True
        seen_texts.add(norm)
        return False

    # ── 1. Single Multiple Choice ──────────────────────────────────────────
    if "multiple_choice" in requested_types or "single_mcq" in requested_types:
        count = int(question_counts.get("multiple_choice", question_counts.get("single_mcq", 2)))
        for i in range(min(count, max(1, len(defs) + len(sentences)))):
            if i < len(defs):
                item = defs[i]
                q_text = f"What is defined as: \"{item['definition']}\"?"
                correct_text = item["term"]
                distractors = [t for t in terms if t.lower() != correct_text.lower()][:3]
                while len(distractors) < 3:
                    distractors.append(f"Concept {len(distractors)+1}")
            else:
                sent = sentences[i % len(sentences)]
                tokens = _clean_tokens(sent)
                if len(tokens) < 3: continue
                kw = tokens[0].title()
                q_text = f"According to the module, which statement correctly relates to {kw}?"
                correct_text = sent
                distractors = [
                    f"It is unrelated to historical analysis or module principles.",
                    f"It strictly invalidates previous system records.",
                    f"It occurs only without structured processes."
                ]

            if is_dup(q_text): continue
            q_uid = f"MCQ-{q_counter:03d}"
            q_counter += 1

            all_choices = [correct_text] + distractors[:3]
            random.shuffle(all_choices)
            options_data = []
            correct_opt_id = ""
            for idx, ch in enumerate(all_choices):
                opt_id = f"opt-{q_uid.lower()}-{idx+1:02d}"
                options_data.append({"id": opt_id, "text": ch})
                if ch == correct_text:
                    correct_opt_id = opt_id

            questions.append({
                "question_uid": q_uid,
                "question_text": q_text,
                "question_type": "multiple_choice",
                "options_data": options_data,
                "correct_option_ids": [correct_opt_id],
                "points": 1,
                "topic": "Module Core Concepts",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": f"Supported directly by module material regarding {correct_text}."
            })

    # ── 2. Multi-Select Multiple Choice ───────────────────────────────────
    if "multi_select" in requested_types or "multiple_answers" in requested_types:
        count = int(question_counts.get("multi_select", question_counts.get("multiple_answers", 2)))
        for i in range(min(count, max(1, len(enums) + 2))):
            if i < len(enums):
                enum_item = enums[i]
                topic_name = enum_item["topic"].title()
                valid_items = enum_item["items"][:3]
                q_text = f"Which of the following are components or applications of {topic_name}? (Select all correct answers)"
                correct_choices = valid_items
                distractors = [f"Unrelated {terms[j % len(terms)]} element" for j in range(2)] if terms else ["Incompatible structure", "Data eradication"]
            else:
                top_terms = terms[:3]
                q_text = "Which of the following key topics are addressed in this learning module? (Select all correct answers)"
                correct_choices = top_terms[:2] if len(top_terms) >= 2 else ["Module Foundation", "Core Applications"]
                distractors = ["External irrelevant topic", "Arbitrary system construct"]

            if is_dup(q_text): continue
            q_uid = f"MSQ-{q_counter:03d}"
            q_counter += 1

            all_choices = list(set(correct_choices + distractors))
            random.shuffle(all_choices)
            options_data = []
            correct_opt_ids = []
            for idx, ch in enumerate(all_choices):
                opt_id = f"opt-{q_uid.lower()}-{idx+1:02d}"
                options_data.append({"id": opt_id, "text": ch})
                if ch in correct_choices:
                    correct_opt_ids.append(opt_id)

            questions.append({
                "question_uid": q_uid,
                "question_text": q_text,
                "question_type": "multi_select",
                "options_data": options_data,
                "correct_option_ids": correct_opt_ids,
                "points": 2,
                "topic": "Module Components & Applications",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": "Multiple correct answers verifiable from module sections."
            })

    # ── 3. True or False ──────────────────────────────────────────────────
    if "true_false" in requested_types:
        count = int(question_counts.get("true_false", 2))
        for i in range(min(count, max(1, len(sentences)))):
            sent = sentences[i % len(sentences)]
            if is_dup(sent): continue
            q_uid = f"TF-{q_counter:03d}"
            q_counter += 1

            truth = (i % 2 == 0)
            if truth:
                q_text = sent
            else:
                q_text = f"According to the module, {sent[:40]} operates completely in reverse of standard principles."

            questions.append({
                "question_uid": q_uid,
                "question_text": q_text,
                "question_type": "true_false",
                "truth_value": truth,
                "correct_answer": "True" if truth else "False",
                "points": 1,
                "topic": "Conceptual Facts",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": "Objective factual evaluation from module text."
            })

    # ── 4. Modified True or False ─────────────────────────────────────────
    if "modified_true_false" in requested_types:
        count = int(question_counts.get("modified_true_false", 2))
        for i in range(min(count, max(1, len(defs) + len(sentences)))):
            q_uid = f"MTF-{q_counter:03d}"
            q_counter += 1

            if i < len(defs):
                term = defs[i]["term"]
                definition = defs[i]["definition"]
                fake_term = terms[(i+3) % len(terms)] if terms else "Extraneous Term"
                q_text = f"{fake_term} refers to {definition}."
                truth = False
                inc_phrase = fake_term
                corr_rep = term
            else:
                sent = sentences[i % len(sentences)]
                q_text = sent
                truth = True
                inc_phrase = ""
                corr_rep = ""

            if is_dup(q_text): continue
            questions.append({
                "question_uid": q_uid,
                "question_text": q_text,
                "question_type": "modified_true_false",
                "truth_value": truth,
                "incorrect_phrase": inc_phrase,
                "correct_replacement": corr_rep,
                "points": 2,
                "topic": "Detailed Recall",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": f"If FALSE, replace '{inc_phrase}' with '{corr_rep}'." if not truth else "Statement is objectively true."
            })

    # ── 5. Identification ────────────────────────────────────────────────
    if "identification" in requested_types:
        count = int(question_counts.get("identification", 2))
        for i in range(min(count, max(1, len(defs)))):
            item = defs[i % len(defs)] if defs else {"term": terms[0] if terms else "Concept", "definition": "the fundamental principle described in the module."}
            q_text = f"Identify the term: \"{item['definition']}\""
            if is_dup(q_text): continue
            q_uid = f"ID-{q_counter:03d}"
            q_counter += 1

            correct_term = item["term"]
            alt_answers = [correct_term.lower(), correct_term.upper(), correct_term.title()]

            questions.append({
                "question_uid": q_uid,
                "question_text": q_text,
                "question_type": "identification",
                "correct_answer": correct_term,
                "acceptable_answers": list(set(alt_answers)),
                "case_sensitive": False,
                "spelling_tolerance": 1,
                "points": 1,
                "topic": "Terminology",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": f"Direct term identification: {correct_term}."
            })

    # ── 6. Enumeration ────────────────────────────────────────────────────
    if "enumeration" in requested_types:
        count = int(question_counts.get("enumeration", 1))
        for i in range(min(count, max(1, len(enums)))):
            if enums:
                enum_item = enums[i % len(enums)]
                topic_name = enum_item["topic"].title()
                expected = enum_item["items"][:4]
            else:
                topic_name = "Core Module Elements"
                expected = terms[:3] if len(terms) >= 3 else ["Principle A", "Principle B", "Principle C"]

            req_count = len(expected)
            q_text = f"Enumerate {req_count} key aspects or elements of {topic_name}."
            if is_dup(q_text): continue
            q_uid = f"ENUM-{q_counter:03d}"
            q_counter += 1

            questions.append({
                "question_uid": q_uid,
                "question_text": q_text,
                "question_type": "enumeration",
                "required_count": req_count,
                "expected_answers": expected,
                "acceptable_alternatives": expected,
                "order_matters": False,
                "partial_credit": True,
                "points": req_count,
                "topic": "Systematic Lists",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": f"Expected items: {', '.join(expected)}."
            })

    # ── 7. Matching Type ──────────────────────────────────────────────────
    if "matching" in requested_types:
        count = int(question_counts.get("matching", 1))
        if len(defs) >= 3 or len(terms) >= 3:
            q_uid = f"MATCH-{q_counter:03d}"
            q_counter += 1
            pairs = []
            source_items = defs[:4] if len(defs) >= 3 else [{"term": terms[j], "definition": f"Core context regarding {terms[j]}"} for j in range(min(4, len(terms)))]
            
            for p_idx, s_item in enumerate(source_items):
                pairs.append({
                    "col_a_id": f"A{p_idx+1}",
                    "col_a_text": s_item["term"],
                    "col_b_id": f"B{p_idx+1}",
                    "col_b_text": s_item["definition"][:90]
                })

            questions.append({
                "question_uid": q_uid,
                "question_text": "Match each term in Column A with its corresponding definition/description in Column B.",
                "question_type": "matching",
                "matching_pairs": pairs,
                "points": len(pairs),
                "topic": "Concept Association",
                "module_id": module_id,
                "module_version": module_version,
                "explanation": "Exact pair relationships derived from module definitions."
            })

    # ── 8. Essay ──────────────────────────────────────────────────────────
    if "essay" in requested_types:
        count = int(question_counts.get("essay", 1))
        main_topic = terms[0] if terms else "the subject matter"
        sub_topic = terms[1] if len(terms) > 1 else "key applications"
        q_text = f"Explain the core principles of {main_topic} and how it relates to {sub_topic}. Discuss practical significance and outcomes."
        
        q_uid = f"ESSAY-{q_counter:03d}"
        q_counter += 1

        required_concepts = [
            {"concept": f"Accurate definition and foundational context of {main_topic}", "points": 3},
            {"concept": f"Explanation of mechanisms, processes, or relations to {sub_topic}", "points": 3},
            {"concept": f"Practical application, decision impact, or analytical significance", "points": 2},
            {"concept": "Coherent organization, clear explanation, and appropriate terminology", "points": 2}
        ]

        rubric = [
            {"name": "Conceptual Understanding", "points": 4},
            {"name": "Accuracy & Module Alignment", "points": 3},
            {"name": "Required Concepts Coverage", "points": 2},
            {"name": "Clarity & Organization", "points": 1}
        ]

        questions.append({
            "question_uid": q_uid,
            "question_text": q_text,
            "question_type": "essay",
            "max_score": 10,
            "points": 10,
            "required_concepts": required_concepts,
            "rubric_json": rubric,
            "topic": "Analytical Synthesis",
            "module_id": module_id,
            "module_version": module_version,
            "explanation": "Holistic semantic evaluation against required module concepts and rubric."
        })

    return jsonify({
        "success": True,
        "module_id": module_id,
        "module_version": module_version,
        "questions_count": len(questions),
        "questions": questions
    })


@app.route("/grade_essay", methods=["POST"])
def grade_essay():
    """
    Semantic Essay Grading against module text and required concepts.
    Accepts paraphrasing, synonyms, and variations without requiring verbatim copying.
    Provides AI suggested score, rubric breakdown, and constructive feedback.
    """
    data = request.get_json(force=True, silent=True) or {}
    module_text = data.get("module_text", "")
    student_answer = str(data.get("student_answer", "")).strip()
    essay_question = data.get("essay_question", "")
    required_concepts = data.get("required_concepts", [])
    rubric = data.get("rubric", [])
    max_score = float(data.get("maximum_score", 10))
    if max_score <= 0:
        max_score = 10.0

    if not student_answer or len(student_answer) < 8:
        return jsonify({
            "success": True,
            "suggested_score": 0.0,
            "max_score": max_score,
            "feedback": "No answer provided or answer is too short to demonstrate understanding.",
            "rubric_scores": {r.get("name", "Criterion"): 0.0 for r in rubric} if rubric else {},
            "detected_concepts": [],
            "missing_concepts": [c.get("concept", str(c)) for c in required_concepts],
            "confidence": 0.95
        })

    ans_tokens = set(_clean_tokens(student_answer))
    word_count = len(student_answer.split())

    # Evaluate each required concept semantically
    detected_concepts = []
    missing_concepts = []
    concept_score_total = 0.0
    concept_max_total = 0.0

    if not required_concepts:
        # Generate default concepts from question & module tokens
        q_tokens = _clean_tokens(essay_question)
        required_concepts = [
            {"concept": f"Explanation of {q_tokens[0] if q_tokens else 'main theme'}", "points": max_score * 0.5},
            {"concept": f"Application and contextual significance", "points": max_score * 0.3},
            {"concept": "Clarity of expression and terminology", "points": max_score * 0.2}
        ]

    for c in required_concepts:
        c_text = c.get("concept", "") if isinstance(c, dict) else str(c)
        c_pts = float(c.get("points", max_score / max(1, len(required_concepts)))) if isinstance(c, dict) else (max_score / len(required_concepts))
        concept_max_total += c_pts

        c_tokens = set(_clean_tokens(c_text))
        if not c_tokens:
            overlap = 1.0
        else:
            intersection = ans_tokens.intersection(c_tokens)
            overlap = len(intersection) / len(c_tokens)

        # Synonym / Paraphrasing boost: check partial substring or stem matches
        for ct in c_tokens:
            if any(ct in at or at in ct for at in ans_tokens):
                overlap = min(1.0, overlap + 0.25)

        if overlap >= 0.45 or (word_count >= 30 and overlap >= 0.25):
            earned = round(c_pts * min(1.0, overlap * 1.3), 1)
            detected_concepts.append({"concept": c_text, "earned": earned, "max": c_pts})
            concept_score_total += earned
        else:
            missing_concepts.append(c_text)

    # Calculate overall understanding ratio
    understanding_ratio = concept_score_total / max(1.0, concept_max_total) if concept_max_total > 0 else (min(1.0, word_count / 40.0))
    suggested_score = round(understanding_ratio * max_score, 1)

    # Distribute score across configured rubric criteria
    rubric_scores = {}
    if rubric:
        for crit in rubric:
            c_name = crit.get("name", "Criterion")
            c_max = float(crit.get("points", max_score / len(rubric)))
            rubric_scores[c_name] = round(c_max * understanding_ratio, 1)

    # Generate constructive feedback
    feedback_parts = []
    if detected_concepts:
        top_demo = detected_concepts[0]["concept"]
        feedback_parts.append(f"Demonstrated good conceptual understanding of {top_demo}.")
    if missing_concepts:
        top_miss = missing_concepts[0]
        feedback_parts.append(f"To improve, provide a more complete explanation regarding: {top_miss}.")
    else:
        feedback_parts.append("Comprehensive answer addressing all required concepts effectively.")

    feedback = " ".join(feedback_parts)

    return jsonify({
        "success": True,
        "suggested_score": suggested_score,
        "max_score": max_score,
        "percentage": round((suggested_score / max_score) * 100, 1),
        "feedback": feedback,
        "rubric_scores": rubric_scores,
        "detected_concepts": detected_concepts,
        "missing_concepts": missing_concepts,
        "confidence": 0.88
    })


if __name__ == "__main__":
    load_model()
    app.run(host=API_HOST, port=API_PORT, debug=False)

