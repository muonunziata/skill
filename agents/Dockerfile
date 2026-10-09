FROM python:3.12-slim
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY . .
RUN useradd --create-home agent && mkdir -p /app/state && chown -R agent /app/state
USER agent
ENV STATE_DIR=/app/state
CMD ["python", "main.py", "watch"]
