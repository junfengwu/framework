使用 Session 存储数据（Storing data in Session）
================================================
The :doc:`Leaps\\Session <../api/Leaps_Session>` provides object-oriented wrappers to access session data.

Reasons to use this component instead of raw-sessions:

* You can easily isolate session data across applications on the same domain
* Intercept where session data is set/get in your application
* Change the session adapter according to the application needs

启动会话（Starting the Session）
--------------------------------
Some applications are session-intensive, almost any action that performs requires access to session data. There are others who access session data casually.
Thanks to the service container, we can ensure that the session is accessed only when it's clearly needed:

.. code-block:: php

    <?php

    //Start the session the first time when some component request the session service
    $di->setShared('session', function() {
        $session = new Leaps\Session\Adapter\Files();
        $session->start();
        return $session;
    });

Session 的存储与读取（Storing/Retrieving data in Session）
----------------------------------------------------------
From a controller, a view or any other component that extends :doc:`Leaps\\DI\\Injectable <../api/Leaps_DI_Injectable>` you can access the session service
and store items and retrieve them in the following way:

.. code-block:: php

    <?php

    class UserController extends Leaps\Mvc\Controller
    {

        public function indexAction()
        {
            //Set a session variable
            $this->session->set("user-name", "Michael");
        }

        public function welcomeAction()
        {

            //Check if the variable is defined
            if ($this->session->has("user-name")) {

                //Retrieve its value
                $name = $this->session->get("user-name");
            }
        }

    }

Sessions 的删除和销毁（Removing/Destroying Sessions）
-----------------------------------------------------
It's also possible remove specific variables or destroy the whole session:

.. code-block:: php

    <?php

    class UserController extends Leaps\Mvc\Controller
    {

        public function removeAction()
        {
            //Remove a session variable
            $this->session->remove("user-name");
        }

        public function logoutAction()
        {
            //Destroy the whole session
            $this->session->destroy();
        }

    }

隔离不同应用的会话数据（Isolating Session Data between Applications）
---------------------------------------------------------------------
Sometimes a user can use the same application twice, on the same server, in the same session. Surely, if we use variables in session,
we want that every application have separate session data (even though the same code and same variable names). To solve this, you can add a
prefix for every session variable created in a certain application:

.. code-block:: php

    <?php

    //Isolating the session data
    $di->set('session', function(){

        //All variables created will prefixed with "my-app-1"
        $session = new Leaps\Session\Adapter\Files(
            array(
                'uniqueId' => 'my-app-1'
            )
        );

        $session->start();

        return $session;
    });

会话袋（Session Bags）
----------------------
:doc:`Leaps\\Session\\Bag <../api/Leaps_Session_Bag>` is a component that helps separating session data into "namespaces".
Working by this way you can easily create groups of session variables into the application. By only setting the variables in the "bag",
it's automatically stored in session:

.. code-block:: php

    <?php

    $user       = new Leaps\Session\Bag('user');
    $user->setDI($di);
    $user->name = "Kimbra Johnson";
    $user->age  = 22;


组件的持久数据（Persistent Data in Components）
-----------------------------------------------
Controller, components and classes that extends :doc:`Leaps\\DI\\Injectable <../api/Leaps_DI_Injectable>` may inject
a :doc:`Leaps\\Session\\Bag <../api/Leaps_Session_Bag>`. This class isolates variables for every class.
Thanks to this you can persist data between requests in every class in an independent way.

.. code-block:: php

    <?php

    class UserController extends Leaps\Mvc\Controller
    {

        public function indexAction()
        {
            // Create a persistent variable "name"
            $this->persistent->name = "Laura";
        }

        public function welcomeAction()
        {
            if (isset($this->persistent->name))
            {
                echo "Welcome, ", $this->persistent->name;
            }
        }

    }

In a component:

.. code-block:: php

    <?php

    class Security extends Leaps\Mvc\User\Component
    {

        public function auth()
        {
            // Create a persistent variable "name"
            $this->persistent->name = "Laura";
        }

        public function getAuthName()
        {
            return $this->persistent->name;
        }

    }

The data added to the session ($this->session) are available throughout the application, while persistent ($this->persistent)
can only be accessed in the scope of the current class.

自定义适配器（Implementing your own adapters）
----------------------------------------------
The :doc:`Leaps\\Session\\AdapterInterface <../api/Leaps_Session_AdapterInterface>` interface must be implemented in order to create your own session adapters or extend the existing ones.

There are more adapters available for this components in the `Leaps Incubator <https://github.com/phalcon/incubator/tree/master/Library/Leaps/Session/Adapter>`_