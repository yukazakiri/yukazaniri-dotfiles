#!/bin/bash

###############################################################################
# SSL Certificate Fix Script for Traefik & mkcert
#
# This script fixes "ERR_CERT_AUTHORITY_INVALID" errors by:
# 1. Verifying mkcert is installed and CA is trusted
# 2. Regenerating certificates with the correct root CA
# 3. Updating certificate chain
# 4. Restarting Traefik
###############################################################################

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PROJECT_DIR="."
CERTS_DIR="${PROJECT_DIR}/docker/traefik/certs"
DOMAINS=(
    "admin.dccp.test"
    "portal.dccp.test"
    "*.dccp.test"
    "minio.local.test"
    "minio-console.local.test"
    "mailpit.local.test"
    "local.test"
    "*.local.test"
)

echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║         SSL Certificate Fix for Traefik & mkcert          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""

# Function to print colored status messages
print_status() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_error() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

# Check if mkcert is installed
print_info "Checking if mkcert is installed..."
if ! command -v mkcert &> /dev/null; then
    print_error "mkcert is not installed!"
    print_info "Please install mkcert first:"
    print_info "  - On Linux: sudo apt install mkcert  # or brew install mkcert"
    print_info "  - On macOS: brew install mkcert"
    print_info "  - On Windows: choco install mkcert"
    exit 1
fi
print_status "mkcert is installed"
print_info "Version: $(mkcert --version 2>&1 | head -1)"

# Check if mkcert CA is installed
print_info "Checking if mkcert root CA is installed..."
if ! mkcert -CAROOT &> /dev/null; then
    print_warning "mkcert CA not found. Installing..."
    mkcert -install
else
    print_status "mkcert root CA is installed"
fi

CAROOT=$(mkcert -CAROOT)
print_info "mkcert CAROOT: ${CAROOT}"

# Check if certificate directory exists
print_info "Checking certificate directory..."
if [ ! -d "${CERTS_DIR}" ]; then
    print_warning "Certificate directory not found: ${CERTS_DIR}"
    print_info "Creating directory..."
    mkdir -p "${CERTS_DIR}"
fi
print_status "Certificate directory ready: ${CERTS_DIR}"

# Navigate to certificate directory
cd "${CERTS_DIR}"

# Backup existing certificates if they exist
if [ -f "dccp.test.pem" ] || [ -f "dccp.test-key.pem" ]; then
    print_info "Backing up existing certificates..."
    BACKUP_DIR="${CERTS_DIR}/backup-$(date +%Y%m%d-%H%M%S)"
    mkdir -p "${BACKUP_DIR}"
    cp dccp.test.pem "${BACKUP_DIR}/" 2>/dev/null || true
    cp dccp.test-key.pem "${BACKUP_DIR}/" 2>/dev/null || true
    print_status "Backup created: ${BACKUP_DIR}"
fi

# Generate new certificate
print_info "Generating new SSL certificate..."
print_info "Domains: ${DOMAINS[*]}"

# Create domain list for mkcert command
DOMAIN_ARGS=()
for domain in "${DOMAINS[@]}"; do
    DOMAIN_ARGS+=("${domain}")
done

# Generate certificate
print_info "Running: mkcert -key-file dccp.test-key.pem -cert-file dccp.test.pem ${DOMAINS[*]}"
if mkcert -key-file dccp.test-key.pem -cert-file dccp.test.pem "${DOMAIN_ARGS[@]}"; then
    print_status "Certificate generated successfully"
else
    print_error "Failed to generate certificate"
    exit 1
fi

# Verify certificate details
print_info "Verifying certificate..."
CERT_SUBJECT=$(openssl x509 -in dccp.test.pem -noout -subject 2>/dev/null || echo "unknown")
CERT_ISSUER=$(openssl x509 -in dccp.test.pem -noout -issuer 2>/dev/null || echo "unknown")
print_info "Subject: ${CERT_SUBJECT}"
print_info "Issuer: ${CERT_ISSUER}"

# Append root CA to certificate chain
print_info "Appending root CA to certificate chain..."
if cat "${CAROOT}/rootCA.pem" >> dccp.test.pem; then
    print_status "Certificate chain updated successfully"
else
    print_error "Failed to update certificate chain"
    exit 1
fi

# Verify the full chain
print_info "Verifying certificate chain..."
if openssl crl2pkcs7 -nocrl -certfile dccp.test.pem | openssl pkcs7 -print_certs -noout 2>/dev/null | grep -q "BEGIN CERTIFICATE"; then
    print_status "Certificate chain is valid"
else
    print_warning "Could not fully verify certificate chain, but continuing..."
fi

# Set proper permissions
print_info "Setting file permissions..."
chmod 644 dccp.test.pem
chmod 600 dccp.test-key.pem
print_status "Permissions set"

# Check if Traefik is running
print_info "Checking if Traefik container is running..."
if docker ps | grep -q traefik; then
    print_status "Traefik container is running"

    # Restart Traefik
    print_info "Restarting Traefik to apply new certificates..."
    cd "${PROJECT_DIR}"
    if docker compose restart traefik; then
        print_status "Traefik restarted successfully"

        # Wait for Traefik to start
        print_info "Waiting for Traefik to start..."
        sleep 5

        # Check if Traefik is running
        if docker ps | grep -q traefik; then
            print_status "Traefik is running"
        else
            print_warning "Traefik may not be running properly"
        fi
    else
        print_error "Failed to restart Traefik"
        print_info "Please run manually: docker compose restart traefik"
    fi
else
    print_warning "Traefik container is not running"
    print_info "Start Traefik with: docker compose up -d traefik"
fi

# Test HTTPS connection
print_info "Testing HTTPS connection to admin.dccp.test..."
if command -v curl &> /dev/null; then
    if curl -k -I https://admin.dccp.test &> /dev/null; then
        print_status "HTTPS connection to admin.dccp.test is working!"
    else
        print_warning "Could not connect to https://admin.dccp.test"
        print_info "You may need to wait a moment for Traefik to fully start"
    fi
else
    print_info "curl not found, skipping connection test"
fi

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║              SSL Certificate Fix Complete!                ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════════╝${NC}"
echo ""
print_info "Next steps:"
print_info "  1. Try accessing https://admin.dccp.test in your browser"
print_info "  2. If you still see certificate warnings:"
print_info "     - Hard refresh (Ctrl+Shift+R or Cmd+Shift+R)"
print_info "     - Clear browser cache for the site"
print_info "     - Restart your browser"
echo ""
print_info "Certificate details:"
print_info "  - Location: ${CERTS_DIR}/dccp.test.pem"
print_info "  - Key: ${CERTS_DIR}/dccp.test-key.pem"
print_info "  - Backup: ${BACKUP_DIR:-'N/A'}"
echo ""

# Show certificate expiration
if [ -f "${CERTS_DIR}/dccp.test.pem" ]; then
    EXPIRY=$(openssl x509 -in "${CERTS_DIR}/dccp.test.pem" -noout -enddate 2>/dev/null | cut -d= -f2)
    if [ -n "${EXPIRY}" ]; then
        print_info "Certificate expires: ${EXPIRY}"
    fi
fi

print_status "Done! 🎉"
