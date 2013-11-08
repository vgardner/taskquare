Drupal Vagrant
==============

A project template for quickly building local Drupal 8 compatible development environments.

### Requirements

* [VirtualBox](http://www.virtualbox.org/)
* [Vagrant](http://www.vagrantup.com/)

### Basic Usage

1. Clone this repo into a local folder:

        host$ git clone git@github.com:vgardner/taskquare.git

2. settings file default db settings are:

        Username: root
        Password: root
        Db name: drupal
        
4. Add VM to hosts file:

        192.168.56.101 taskquare.dev

5. You can log into the VM via:

        host$ vagrant up
        host$ vagrant ssh
        
6. Access your VM via taskquare.dev:8080 
        
7. You can copy a sql dump into drupal-vagrant/sql named drupal.sql and Vagrant will automatically import your db on vagrant up.


