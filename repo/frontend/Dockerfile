FROM node:24-bookworm-slim

WORKDIR /opt/frontend-seed
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

WORKDIR /workspace/frontend
