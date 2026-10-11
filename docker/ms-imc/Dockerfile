FROM python:3.10-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

EXPOSE 5001
# Servidor de producción (gunicorn) en lugar del servidor de desarrollo de Flask
CMD ["gunicorn", "--bind", "0.0.0.0:5001", "--workers", "2", "app:app"]
