# CenLearn ML API

Python/Flask micro-service that adds a real Machine Learning layer to the
CenLearn predictive analytics engine.

## How it works

```
PHP (analytics_engine.php)
  │
  ├─ Runs rule-based scoring (SQL queries → weighted penalties)
  │
  └─ Calls Python API → POST http://127.0.0.1:5001/predict
        │
        └─ Random Forest model predicts risk level
              │
              └─ PHP blends both: final = rule(60%) + ML(40%)
```

When the Python API is **offline**, PHP silently falls back to rule-based only.
No errors, no broken pages.

---

## Setup

### 1. Install Python dependencies

```bash
cd ml_api
pip install -r requirements.txt
```

### 2. Configure database (if needed)

Edit `config.py` — default settings match a fresh XAMPP install:
```python
DB_CONFIG = {
    "host":     "localhost",
    "user":     "root",
    "password": "",           # blank for default XAMPP
    "database": "cenlearn_db",
}
```

### 3. Train the model

```bash
python train.py
```

This reads your student data from MySQL, computes features, and saves
the trained model to `model/risk_model.pkl`.

> **Note:** The model bootstraps from the rule-based scores as training labels.
> As real outcome data accumulates (pass/fail, grades), you can retrain with
> actual labels for better accuracy.

### 4. Start the API server

```bash
python app.py
```

The server runs at `http://127.0.0.1:5001`.

---

## Endpoints

| Method | Path       | Description                        |
|--------|------------|------------------------------------|
| GET    | /health    | Check if server + model are loaded |
| POST   | /predict   | Predict risk for one student       |
| POST   | /retrain   | Retrain model without restarting   |

### POST /predict — example

```json
{
  "quiz_avg_pct":   0.72,
  "assign_avg_pct": 0.65,
  "missed_count":   1,
  "attend_rate":    0.80,
  "late_rate":      0.10
}
```

Response:
```json
{
  "ml_level":      "on_track",
  "ml_label":      "On Track",
  "ml_score":      15,
  "ml_confidence": 0.87,
  "probabilities": {
    "on_track":  0.87,
    "attention": 0.10,
    "at_risk":   0.02,
    "high_risk": 0.01
  },
  "model_active": true
}
```

---

## Files

```
ml_api/
├── app.py          ← Flask API server & WSGI handler
├── train.py        ← Model training script
├── config.py       ← DB + server config
├── vercel.json     ← Vercel serverless deployment config
├── requirements.txt
├── model/
│   └── risk_model.pkl   ← generated after running train.py
└── README.md

---

## Deployment to Vercel

1. Install Vercel CLI: `npm i -g vercel`
2. Run `vercel` inside the `ml_api` directory.
3. Your API will be deployed serverlessly at `https://your-project.vercel.app`.

```
