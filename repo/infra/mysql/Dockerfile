FROM mysql:8.4

COPY infra/mysql/mysql_entrypoint.sh /usr/local/bin/mysql_entrypoint.sh
RUN chmod +x /usr/local/bin/mysql_entrypoint.sh

ENTRYPOINT ["/usr/local/bin/mysql_entrypoint.sh"]
CMD ["mysqld"]
