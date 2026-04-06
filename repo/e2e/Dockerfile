FROM mcr.microsoft.com/playwright:v1.59.1-noble

WORKDIR /opt/frontend-seed
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

WORKDIR /workspace/frontend
